<?php

declare(strict_types=1);

namespace livehand\abtestcraft\services;

use Craft;
use craft\base\Component;
use DateTime;
use livehand\abtestcraft\models\Test;
use livehand\abtestcraft\records\TestRecord;
use livehand\abtestcraft\ABTestCraft;

/**
 * Notification service - handles email notifications for split tests
 */
class NotificationService extends Component
{
    /**
     * Cooldown period in seconds between significance notifications (1 hour)
     */
    private const NOTIFICATION_COOLDOWN_SECONDS = 3600;

    /**
     * Send email notification when a test reaches statistical significance
     *
     * @param Test $test The test that reached significance
     * @param array $stats The test statistics including conversion rates and confidence
     */
    public function sendSignificanceEmail(Test $test, array $stats): void
    {
        $settings = ABTestCraft::getInstance()->getSettings();

        if (!$settings->sendSignificanceEmail || empty($settings->notificationEmails)) {
            return;
        }

        // Use the new helper method for validated emails
        $emails = $settings->getNotificationEmailsArray();

        if (empty($emails)) {
            return;
        }

        $winner = $stats['conversionRates']['variant'] > $stats['conversionRates']['control']
            ? 'Variant'
            : 'Control';

        $subject = "Split Test '{$test->name}' has reached statistical significance";

        $body = $this->buildSignificanceEmailBody($test, $stats, $winner);

        $sentCount = 0;
        foreach ($emails as $email) {
            try {
                Craft::$app->getMailer()->compose()
                    ->setTo($email)
                    ->setSubject($subject)
                    ->setTextBody($body)
                    ->send();
                $sentCount++;
            } catch (\Throwable $e) {
                Craft::error(
                    "Failed to send significance email to {$email}: " . $e->getMessage(),
                    __METHOD__
                );
            }
        }

        if ($sentCount > 0) {
            Craft::info(
                "Significance notification sent for test '{$test->handle}' to {$sentCount} recipients",
                'abtestcraft'
            );

            // Update the notification timestamp
            $this->updateNotificationTimestamp($test);
        }
    }

    /**
     * Build the email body for significance notification
     */
    private function buildSignificanceEmailBody(Test $test, array $stats, string $winner): string
    {
        $baseUrl = Craft::$app->getSites()->getCurrentSite()->getBaseUrl();
        $resultsUrl = rtrim($baseUrl, '/') . "/admin/abtestcraft/tests/{$test->id}/results";

        $controlRate = (float) $stats['conversionRates']['control'];
        $variantRate = (float) $stats['conversionRates']['variant'];

        // Calculate relative lift (percentage improvement)
        $relativeLift = $controlRate > 0
            ? (($variantRate - $controlRate) / $controlRate) * 100
            : 0;
        $relativeLiftFormatted = ($relativeLift >= 0 ? '+' : '') . number_format($relativeLift, 1) . '%';

        // Calculate absolute difference (percentage points)
        $absoluteDiff = $variantRate - $controlRate;
        $absoluteDiffFormatted = ($absoluteDiff >= 0 ? '+' : '') . number_format($absoluteDiff, 2) . ' pp';

        $body = "Your split test '{$test->name}' has reached statistical significance!\n\n";

        $body .= "WINNER: {$winner}\n";
        $body .= "Confidence: " . number_format($stats['confidence'] * 100, 1) . "%\n\n";

        $body .= "PERFORMANCE\n";
        $body .= "Relative lift: {$relativeLiftFormatted}\n";
        $body .= "Absolute lift: {$absoluteDiffFormatted}\n\n";

        $body .= "CONVERSION RATES\n";
        $body .= "Control: {$stats['conversionRates']['control']}% ";
        $body .= "({$stats['convertedVisitors']['control']}/{$stats['visitors']['control']} visitors)\n";
        $body .= "Variant: {$stats['conversionRates']['variant']}% ";
        $body .= "({$stats['convertedVisitors']['variant']}/{$stats['visitors']['variant']} visitors)\n\n";

        $body .= "View full results: {$resultsUrl}";

        return $body;
    }

    /**
     * Check if significance notification should be sent for a test
     * and send it if conditions are met
     *
     * Includes cooldown check to prevent sending multiple notifications
     * in rapid succession.
     */
    public function checkAndNotifySignificance(Test $test): void
    {
        // Check cooldown first to avoid expensive stats calculation
        if (!$this->canSendNotification($test)) {
            return;
        }

        $stats = ABTestCraft::getInstance()->stats->getTestStats($test);

        if ($stats['isSignificant']) {
            $this->sendSignificanceEmail($test, $stats);
        }
    }

    /**
     * Check if enough time has passed since the last notification
     *
     * @param Test $test The test to check
     * @return bool True if notification can be sent, false if still in cooldown
     */
    private function canSendNotification(Test $test): bool
    {
        if (!$test->id) {
            return false;
        }

        // Check the significance notified timestamp
        if ($test->significanceNotifiedAt === null) {
            return true;
        }

        $lastNotified = $test->significanceNotifiedAt;
        $cooldownEnd = (clone $lastNotified)->modify('+' . self::NOTIFICATION_COOLDOWN_SECONDS . ' seconds');
        $now = new DateTime();

        return $now > $cooldownEnd;
    }

    /**
     * Update the notification timestamp for a test
     *
     * @param Test $test The test to update
     */
    private function updateNotificationTimestamp(Test $test): void
    {
        if (!$test->id) {
            return;
        }

        try {
            $now = (new DateTime())->format('Y-m-d H:i:s');

            Craft::$app->getDb()->createCommand()
                ->update(
                    '{{%abtestcraft_tests}}',
                    ['significanceNotifiedAt' => $now],
                    ['id' => $test->id]
                )
                ->execute();

            // Update the model instance too
            $test->significanceNotifiedAt = new DateTime($now);
        } catch (\Throwable $e) {
            Craft::error(
                "Failed to update significance notification timestamp for test '{$test->handle}': " . $e->getMessage(),
                __METHOD__
            );
        }
    }
}
