<?php

declare(strict_types=1);

namespace HitechFibre\Bot;

use HitechFibre\Core\Logger;
use HitechFibre\Services\SplynxService;
use HitechFibre\Services\RespondIOService;

/**
 * Bot Engine — the main orchestrator.
 *
 * For each incoming message it:
 *  1. Loads the conversation session from the StateMachine
 *  2. Checks after-hours rules
 *  3. Detects intent from IntentDetector
 *  4. Routes to the correct FlowManager handler
 *  5. Sends the reply via RespondIOService
 *  6. Persists the updated session
 */
class Engine
{
    public function __construct(
        private readonly StateMachine    $sm,
        private readonly IntentDetector  $intent,
        private readonly FlowManager     $flow,
        private readonly SplynxService   $splynx,
        private readonly RespondIOService $respondIO,
        private readonly Logger          $logger,
        private readonly array           $config,
    ) {}

    /**
     * Process a single incoming message event.
     * Returns the reply text that was sent (or null if paused/escalated/after-hours).
     */
    public function handle(
        string $phone,
        string $text,
        string $contactName,
        string $conversationId,
    ): ?string {
        $session = $this->sm->getSession($phone, $conversationId);

        // Update contact name if we got a better one
        if (!empty($contactName) && empty($session['contact_name'])) {
            $session['contact_name'] = $contactName;
        }

        // Record incoming message
        $this->sm->addMessage($session, 'customer', $text);

        // ── Check if bot is paused for this contact ──────────────────
        if ($session['state'] === StateMachine::S_ESCALATED) {
            $this->logger->info("Engine: bot paused for escalated conversation", ['phone' => $phone]);
            $this->sm->saveSession($session);
            return null;
        }

        // ── After-hours check ────────────────────────────────────────
        if ($this->isAfterHours()) {
            return $this->handleAfterHours($session);
        }

        // ── Human handover request ───────────────────────────────────
        $detectedIntent = $this->intent->classify($text);
        if ($detectedIntent === 'human_request' || $this->sm->humanRequested($session)) {
            return $this->escalateToHuman($session, 'Customer requested human agent');
        }

        // ── NEW session: greet and identify ─────────────────────────
        if ($session['state'] === StateMachine::S_NEW) {
            return $this->handleNewSession($session, $text, $detectedIntent);
        }

        // ── Route based on current state + incoming intent ───────────
        return $this->route($session, $text, $detectedIntent);
    }

    // ─────────────────────────────────────────────────────────────────
    //  Routing logic
    // ─────────────────────────────────────────────────────────────────

    private function route(array &$session, string $text, string $detectedIntent): ?string
    {
        return match ($session['state']) {
            StateMachine::S_NEW,
            StateMachine::S_IDENTIFIED,
            StateMachine::S_UNKNOWN    => $this->handleIdentified($session, $text, $detectedIntent),
            StateMachine::S_TECH       => $this->flow->handleTechSupport($session, $text, $detectedIntent),
            StateMachine::S_ACCOUNTS   => $this->flow->handleAccounts($session, $text, $detectedIntent),
            StateMachine::S_SALES      => $this->flow->handleSales($session, $text, $detectedIntent),
            StateMachine::S_WAITING    => $this->handleWaiting($session, $text, $detectedIntent),
            StateMachine::S_AFTER_HOURS => $this->handleAfterHours($session),
            default                    => $this->handleUnknownState($session, $text, $detectedIntent),
        };
    }

    private function handleNewSession(array &$session, string $text, string $detectedIntent): ?string
    {
        $name = $session['contact_name'] ?? 'there';
        $firstName = explode(' ', $name)[0];

        // Try to identify the customer
        $customer = null;
        try {
            $customer = $this->splynx->findByPhone($session['phone']);
        } catch (\Throwable $e) {
            $this->logger->error("Engine: Splynx lookup failed", ['error' => $e->getMessage()]);
        }

        if ($customer) {
            $this->sm->setCustomer($session, $customer);
            $this->sm->transition($session, StateMachine::S_IDENTIFIED, 'Customer found in Splynx');

            // Check overdue
            try {
                $overdue = $this->splynx->isAccountOverdue($customer['id']);
                $this->sm->setOverdue($session, $overdue);
            } catch (\Throwable) {}

            if ($this->sm->isOverdue($session)) {
                // Route directly to accounts
                $this->sm->transition($session, StateMachine::S_ACCOUNTS, 'Account overdue');
                $reply = $this->flow->buildAccountsOverdueGreeting($session, $firstName);
            } elseif ($detectedIntent !== 'greeting' && $detectedIntent !== 'unknown') {
                // Jump straight to the intent
                $this->sm->setIntent($session, $detectedIntent);
                $reply = $this->routeByIntent($session, $text, $detectedIntent);
            } else {
                $reply = $this->buildDepartmentMenu($firstName);
            }
        } else {
            $this->sm->transition($session, StateMachine::S_UNKNOWN, 'Not found in Splynx');
            $reply = $this->buildUnknownCustomerGreeting($firstName);
        }

        $this->sendReply($session, $reply);
        return $reply;
    }

    private function handleIdentified(array &$session, string $text, string $detectedIntent): ?string
    {
        if ($detectedIntent !== 'unknown' && $detectedIntent !== 'greeting' && $detectedIntent !== 'affirmative') {
            $this->sm->setIntent($session, $detectedIntent);
            $reply = $this->routeByIntent($session, $text, $detectedIntent);
        } else {
            $reply = $this->buildDepartmentMenu($session['contact_name'] ?? '');
        }

        $this->sendReply($session, $reply);
        return $reply;
    }

    private function routeByIntent(array &$session, string $text, string $intent): string
    {
        return match ($intent) {
            'tech_support' => $this->startTechSupport($session, $text),
            'accounts'     => $this->startAccounts($session),
            'sales'        => $this->startSales($session),
            default        => $this->buildDepartmentMenu($session['contact_name'] ?? ''),
        };
    }

    private function handleWaiting(array &$session, string $text, string $detectedIntent): ?string
    {
        // Bot is waiting for specific info — check the intent of the response
        $waitingFor = $this->sm->getWaitingFor($session);

        $reply = match ($waitingFor) {
            'reboot'   => $this->flow->handleRebootResponse($session, $text, $detectedIntent),
            'lights'   => $this->flow->handleLightsResponse($session, $text),
            'address'  => $this->flow->handleAddressResponse($session, $text),
            'issue'    => $this->flow->handleIssueResponse($session, $text),
            default    => $this->route($session, $text, $detectedIntent),
        };

        if ($reply) {
            $this->sendReply($session, $reply);
        }
        return $reply;
    }

    private function handleUnknownState(array &$session, string $text, string $detectedIntent): ?string
    {
        $this->logger->warning("Engine: unexpected state", ['state' => $session['state'], 'phone' => $session['phone']]);
        $reply = $this->buildDepartmentMenu($session['contact_name'] ?? '');
        $this->sendReply($session, $reply);
        return $reply;
    }

    // ─────────────────────────────────────────────────────────────────
    //  Department openers
    // ─────────────────────────────────────────────────────────────────

    private function startTechSupport(array &$session, string $text): string
    {
        $this->sm->transition($session, StateMachine::S_TECH, 'Technical support request');
        $this->sm->setIssueDescription($session, $text);
        return $this->flow->openTechSupport($session);
    }

    private function startAccounts(array &$session): string
    {
        $this->sm->transition($session, StateMachine::S_ACCOUNTS, 'Accounts request');
        return $this->flow->openAccounts($session);
    }

    private function startSales(array &$session): string
    {
        $this->sm->transition($session, StateMachine::S_SALES, 'Sales request');
        return $this->flow->openSales($session);
    }

    // ─────────────────────────────────────────────────────────────────
    //  Escalation
    // ─────────────────────────────────────────────────────────────────

    public function escalateToHuman(array &$session, string $reason): string
    {
        $this->sm->setEscalationReason($session, $reason);
        $this->sm->transition($session, StateMachine::S_ESCALATED, $reason);
        $reply = "✋ *Connecting you to a support agent now.*\n\nPlease hold on — a team member will be with you shortly. Your reference is *#{$session['id']}*.";
        $this->sendReply($session, $reply);
        $this->logger->info("Engine: escalated to human", ['phone' => $session['phone'], 'reason' => $reason]);
        return $reply;
    }

    // ─────────────────────────────────────────────────────────────────
    //  After-hours
    // ─────────────────────────────────────────────────────────────────

    private function handleAfterHours(array &$session): string
    {
        $this->sm->transition($session, StateMachine::S_AFTER_HOURS, 'After-hours message');
        $hours = $this->config['business_hours'] ?? ['start' => '08:00', 'end' => '17:00'];
        $reply  = "Thank you for contacting *HitechFibre Support*.\n\n"
                 . "Our office hours are *{$hours['start']} – {$hours['end']}*, Monday to Friday.\n\n"
                 . "We have logged your message and will contact you first thing when we open. "
                 . "For urgent outages please call *{$this->config['emergency_number'] ?? '0800 000 000'}*.";
        $this->sendReply($session, $reply);
        return $reply;
    }

    private function isAfterHours(): bool
    {
        $tz = new \DateTimeZone($this->config['timezone'] ?? 'Africa/Johannesburg');
        $now = new \DateTime('now', $tz);

        $dow = (int) $now->format('N'); // 1=Mon, 7=Sun
        if ($dow >= 6) return true;     // Saturday / Sunday = after hours

        $hours  = $this->config['business_hours'] ?? ['start' => '08:00', 'end' => '17:00'];
        $hStart = \DateTime::createFromFormat('H:i', $hours['start'], $tz);
        $hEnd   = \DateTime::createFromFormat('H:i', $hours['end'], $tz);

        return $now < $hStart || $now >= $hEnd;
    }

    // ─────────────────────────────────────────────────────────────────
    //  Message templates
    // ─────────────────────────────────────────────────────────────────

    private function buildDepartmentMenu(string $name): string
    {
        $first = $name ? explode(' ', $name)[0] : '';
        $greeting = $first ? "Hi *{$first}*! " : "Hi! ";
        return $greeting . "Welcome to *HitechFibre Support* 👋\n\n"
             . "How can we help you today?\n\n"
             . "1️⃣  Technical support (no internet, slow speed)\n"
             . "2️⃣  Accounts & billing (payments, invoices)\n"
             . "3️⃣  New connection or upgrade\n"
             . "4️⃣  Speak to an agent\n\n"
             . "_Reply with a number or describe your issue._";
    }

    private function buildUnknownCustomerGreeting(string $firstName): string
    {
        $g = $firstName ? "Hi *{$firstName}*! " : "Hi! ";
        return $g . "Welcome to *HitechFibre Support* 👋\n\n"
             . "We couldn't find your account linked to this number. "
             . "Please reply with your *account number or service address* so we can assist you.";
    }

    // ─────────────────────────────────────────────────────────────────
    //  Reply sender
    // ─────────────────────────────────────────────────────────────────

    private function sendReply(array &$session, string $text): void
    {
        try {
            $this->respondIO->sendMessage($session['phone'], $text, $session['conversation_id'] ?? '');
        } catch (\Throwable $e) {
            $this->logger->error("Engine: failed to send reply", [
                'phone' => $session['phone'], 'error' => $e->getMessage(),
            ]);
        }

        $this->sm->addMessage($session, 'bot', $text);
        $this->sm->saveSession($session);
    }
}
