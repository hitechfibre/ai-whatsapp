<?php

declare(strict_types=1);

namespace HitechFibre\Bot;

use HitechFibre\Core\Logger;
use HitechFibre\Services\SplynxService;

/**
 * FlowManager handles multi-step flows for each department.
 *
 * Design rule: NEVER ask for info the session already has.
 * Every step checks session context before prompting.
 */
class FlowManager
{
    public function __construct(
        private readonly StateMachine  $sm,
        private readonly SplynxService $splynx,
        private readonly Logger        $logger,
    ) {}

    // ═══════════════════════════════════════════════════════════════
    //  TECHNICAL SUPPORT FLOW
    // ═══════════════════════════════════════════════════════════════

    /** Called when tech support is first entered. */
    public function openTechSupport(array &$session): string
    {
        $customer = $this->sm->getCustomer($session);

        // If we already know their address from Splynx, confirm it
        $address = $customer['address'] ?? $customer['street'] ?? null;

        if ($address && !$this->sm->addressConfirmed($session)) {
            $this->sm->setWaitingFor($session, 'address');
            return "I can see your service address is *{$address}*.\n\nIs that the address experiencing the problem? (Yes / No)";
        }

        if (!$this->sm->addressConfirmed($session)) {
            $this->sm->setWaitingFor($session, 'address');
            return "Please provide the *service address* where you are experiencing the problem.";
        }

        return $this->nextTechStep($session);
    }

    /** Handle incoming messages in TECH_SUPPORT state. */
    public function handleTechSupport(array &$session, string $text, string $intent): ?string
    {
        // Human handover check
        if ($intent === 'human_request') {
            return null; // Engine will handle escalation
        }

        $waitingFor = $this->sm->getWaitingFor($session);

        return match ($waitingFor) {
            'address' => $this->handleAddressResponse($session, $text),
            'reboot'  => $this->handleRebootResponse($session, $text, $intent),
            'lights'  => $this->handleLightsResponse($session, $text),
            'issue'   => $this->handleIssueResponse($session, $text),
            default   => $this->nextTechStep($session),
        };
    }

    public function handleAddressResponse(array &$session, string $text): string
    {
        $intent   = strtolower(trim($text));
        $isYes    = in_array($intent, ['yes', 'ja', 'yeah', 'y', '1', 'correct', 'that is correct', 'yes that is correct'], true);
        $isNo     = in_array($intent, ['no', 'nee', 'n', '2', 'wrong', 'incorrect'], true);

        if (!$this->sm->addressConfirmed($session)) {
            if ($isNo) {
                // Ask for the correct address
                return "Please provide the correct *service address* where you are having the problem.";
            }
            if ($isYes || !$isNo) {
                // Either confirmed, or they typed an address directly
                $this->sm->setAddressConfirmed($session, true);
                if (!$isYes) {
                    // They typed a new address
                    $address = $this->extractAddressFromText($text);
                    if ($address) {
                        $this->sm->set($session, 'reported_address', $address);
                    }
                }
            }
        }

        return $this->nextTechStep($session);
    }

    public function handleRebootResponse(array &$session, string $text, string $intent): string
    {
        $lower = mb_strtolower($text);

        $rebooted = $intent === 'affirmative'
            || str_contains($lower, 'yes')
            || str_contains($lower, 'ja')
            || str_contains($lower, 'rebooted')
            || str_contains($lower, 'restarted')
            || str_contains($lower, 'turned off');

        $this->sm->setRebooted($session, true); // Mark as handled regardless

        if (!$rebooted) {
            // Ask them to reboot and come back
            return "Please *switch your router off* for 30 seconds, then switch it back on.\n\n"
                 . "Once the lights have settled (about 2 minutes), please reply here and let me know "
                 . "what colour the lights on your router are.";
        }

        return $this->nextTechStep($session);
    }

    public function handleLightsResponse(array &$session, string $text): string
    {
        // Store the lights description
        $this->sm->setLightsReported($session, true);
        $this->sm->set($session, 'lights_description', $text);
        return $this->nextTechStep($session);
    }

    public function handleIssueResponse(array &$session, string $text): string
    {
        $existing = $this->sm->getIssueDescription($session);
        if (!$existing) {
            $this->sm->setIssueDescription($session, $text);
        }
        return $this->nextTechStep($session);
    }

    /**
     * Determine the next unanswered question in the tech flow.
     * NEVER asks the same question twice.
     */
    private function nextTechStep(array &$session): string
    {
        // Step 1: Address
        if (!$this->sm->addressConfirmed($session)) {
            $this->sm->setWaitingFor($session, 'address');
            return "To assist you, please confirm your *service address*.";
        }

        // Step 2: Issue description
        if (!$this->sm->getIssueDescription($session)) {
            $this->sm->setWaitingFor($session, 'issue');
            return "Please describe the problem you are experiencing in a bit more detail — "
                 . "e.g. *no internet at all*, *very slow speeds*, *intermittent drops*, etc.";
        }

        // Step 3: Reboot
        if (!$this->sm->hasRebooted($session)) {
            $this->sm->setWaitingFor($session, 'reboot');
            return "Before we log a fault, let's try a quick fix: have you *rebooted your router* in the last 15 minutes? (Yes / No)";
        }

        // Step 4: Lights description
        if (!$this->sm->lightsReported($session)) {
            $this->sm->setWaitingFor($session, 'lights');
            return "What do the *lights on your router* look like right now? "
                 . "(e.g. Power = green, Internet/WAN = red blinking)";
        }

        // ── All info collected → escalate to human tech ──────────────
        $this->sm->setWaitingFor($session, '');
        return $this->buildTechEscalationMessage($session);
    }

    private function buildTechEscalationMessage(array &$session): string
    {
        $issue  = $this->sm->getIssueDescription($session);
        $lights = $this->sm->ctx($session, 'lights_description', 'not provided');
        $addr   = $this->sm->ctx($session, 'reported_address')
               ?? ($this->sm->getCustomer($session)['address'] ?? 'see Splynx');

        $summary = "✅ Thank you! I have all the information needed.\n\n"
                 . "I'm escalating your fault to our *technical team* now with the following details:\n"
                 . "• *Issue:* {$issue}\n"
                 . "• *Address:* {$addr}\n"
                 . "• *Router lights:* {$lights}\n\n"
                 . "A technician will be in touch within *2 hours* (during business hours). "
                 . "Your reference is *#{$session['id']}*.";

        // Transition to escalated — bot pauses here
        $this->sm->transition($session, StateMachine::S_ESCALATED, 'Tech info collected, passing to human');
        return $summary;
    }

    // ═══════════════════════════════════════════════════════════════
    //  ACCOUNTS FLOW
    // ═══════════════════════════════════════════════════════════════

    public function openAccounts(array &$session): string
    {
        $customer = $this->sm->getCustomer($session);
        $name     = $this->sm->ctx($session, 'contact_name') ?? 'there';

        if (!$customer) {
            return "Please provide your *account number or service address* so I can pull up your account details.";
        }

        $status = $customer['status'] ?? 'unknown';
        return "I can see your account for *{$customer['name']}*.\n\n"
             . "Account status: *{$status}*\n\n"
             . "What can I help you with?\n"
             . "1️⃣  Outstanding balance / payment\n"
             . "2️⃣  Update payment details\n"
             . "3️⃣  Request invoice / statement\n"
             . "4️⃣  Query a charge\n"
             . "5️⃣  Speak to accounts team";
    }

    public function buildAccountsOverdueGreeting(array &$session, string $firstName): string
    {
        return "Hi *{$firstName}*, welcome to *HitechFibre Support*.\n\n"
             . "⚠️ We notice your account currently has an *outstanding balance*.\n\n"
             . "Our accounts team can assist with payment arrangements. Would you like to:\n"
             . "1️⃣  Make a payment now\n"
             . "2️⃣  Set up a debit order\n"
             . "3️⃣  Speak to accounts team\n"
             . "4️⃣  Query the balance";
    }

    public function handleAccounts(array &$session, string $text, string $intent): ?string
    {
        if ($intent === 'human_request' || in_array(trim($text), ['5', 'five'], true)) {
            return null; // escalate
        }
        // For now, escalate most accounts queries to a human
        return null;
    }

    // ═══════════════════════════════════════════════════════════════
    //  SALES FLOW
    // ═══════════════════════════════════════════════════════════════

    public function openSales(array &$session): string
    {
        return "Great! I'd love to help you get connected 🎉\n\n"
             . "To check *fibre availability* in your area, please reply with your *full street address* "
             . "(e.g. 45 Main Road, Centurion, Pretoria).";
    }

    public function handleSales(array &$session, string $text, string $intent): ?string
    {
        if ($intent === 'human_request') return null;
        // Capture address and escalate to sales team
        $this->sm->set($session, 'sales_address', $text);
        return null; // escalate to sales team
    }

    // ─────────────────────────────────────────────────────────────────

    private function extractAddressFromText(string $text): ?string
    {
        if (preg_match('/\d+\s+[A-Za-z\s]+(?:street|st|road|rd|avenue|ave|drive|dr|close|cl|crescent|cres)/i', $text, $m)) {
            return trim($m[0]);
        }
        if (strlen($text) < 120 && preg_match('/^\d+/', $text)) {
            return trim($text);
        }
        return null;
    }
}
