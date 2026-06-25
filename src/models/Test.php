<?php

declare(strict_types=1);

namespace livehand\abtestcraft\models;

use Craft;
use craft\base\Model;
use craft\elements\Entry;
use craft\helpers\StringHelper;
use craft\validators\HandleValidator;
use DateTime;
use livehand\abtestcraft\ABTestCraft;
use livehand\abtestcraft\records\TestRecord;

/**
 * Test model
 */
class Test extends Model
{
    public ?int $id = null;
    public ?int $siteId = null;
    public string $name = '';
    public string $handle = '';
    public ?string $hypothesis = null;
    public ?string $variantDescription = null;
    public ?string $learnings = null;
    public string $status = 'draft';
    public ?int $controlEntryId = null;
    public ?int $variantEntryId = null;
    public int $trafficSplit = 50;
    public string $goalType = 'form';
    public ?string $goalValue = null;
    public ?DateTime $startedAt = null;
    public ?DateTime $endedAt = null;
    public ?string $winnerVariant = null;
    public ?DateTime $significanceNotifiedAt = null;
    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;
    public ?DateTime $dateDeleted = null;
    public ?string $uid = null;

    // Status constants
    public const STATUS_DRAFT = 'draft';
    public const STATUS_RUNNING = 'running';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_COMPLETED = 'completed';

    // Variant constants
    public const VARIANT_CONTROL = 'control';
    public const VARIANT_VARIANT = 'variant';

    // Goal type constants
    public const GOAL_PHONE = 'phone';
    public const GOAL_FORM = 'form';
    public const GOAL_PAGE = 'page';
    public const GOAL_EMAIL = 'email';
    public const GOAL_DOWNLOAD = 'download';

    public function defineRules(): array
    {
        return [
            [['name', 'handle', 'controlEntryId', 'variantEntryId', 'goalType'], 'required'],
            [['name', 'handle'], 'string', 'max' => 255],
            [['trafficSplit'], 'integer', 'min' => 0, 'max' => 100],
            [['status'], 'in', 'range' => [self::STATUS_DRAFT, self::STATUS_RUNNING, self::STATUS_PAUSED, self::STATUS_COMPLETED]],
            [['goalType'], 'in', 'range' => [self::GOAL_PHONE, self::GOAL_FORM, self::GOAL_PAGE, self::GOAL_EMAIL, self::GOAL_DOWNLOAD]],
            [['goalValue'], 'string', 'max' => 500],
            [['winnerVariant'], 'in', 'range' => [self::VARIANT_CONTROL, self::VARIANT_VARIANT, null]],
            // Use Craft's standard handle convention (camelCase, the same format
            // Craft.HandleGenerator produces in the form) plus its reserved-word check.
            [['handle'], HandleValidator::class],
            // The DB enforces a unique index on `handle`; validate it here so a
            // collision surfaces as a friendly form error rather than a 500.
            [['handle'], 'validateUniqueHandle'],
            // Prevent same entry for control and variant
            [['variantEntryId'], 'compare', 'compareAttribute' => 'controlEntryId', 'operator' => '!=', 'message' => 'Control and variant entries must be different'],
            // Prevent circular reference - variant cannot be a descendant of control
            [['variantEntryId'], 'validateNotDescendantOfControl'],
        ];
    }

    /**
     * Validate that the variant entry is not a descendant of the control entry
     * Prevents circular references that could cause infinite loops
     */
    public function validateNotDescendantOfControl(string $attribute): void
    {
        if (!$this->controlEntryId || !$this->variantEntryId) {
            return;
        }

        // Check if variant is a descendant of control
        $isDescendant = Entry::find()
            ->descendantOf($this->controlEntryId)
            ->id($this->variantEntryId)
            ->siteId($this->siteId)
            ->exists();

        if ($isDescendant) {
            $this->addError($attribute, 'The variant entry cannot be a child/descendant of the control entry. This would create a circular reference.');
        }
    }

    public function attributeLabels(): array
    {
        return [
            'name' => 'Test Name',
            'handle' => 'Handle',
            'hypothesis' => 'Hypothesis',
            'variantDescription' => 'Variant Changes',
            'learnings' => 'Learnings',
            'controlEntryId' => 'Control Entry (Original)',
            'variantEntryId' => 'Variant Entry (Test)',
            'trafficSplit' => 'Traffic to Variant (%)',
            'goalType' => 'Goal Type',
            'goalValue' => 'Goal Value',
        ];
    }

    /**
     * Validate that the handle is unique.
     *
     * The DB has a global unique index on `handle` (including trashed rows), so
     * a collision must be caught here to avoid an unhandled integrity violation.
     */
    public function validateUniqueHandle(string $attribute): void
    {
        if (empty($this->handle)) {
            return;
        }

        if ($this->handleExists($this->handle)) {
            $this->addError($attribute, Craft::t('abtestcraft', 'The handle “{handle}” is already in use.', [
                'handle' => $this->handle,
            ]));
        }
    }

    /**
     * Generate a handle from the name when none was explicitly provided.
     *
     * Uses Craft's StringHelper::toHandle() so the result is a valid camelCase
     * handle (matching Craft.HandleGenerator in the form). Only an auto-generated
     * handle is uniquified (a numeric suffix is appended on collision, matching
     * the DB's unique index on `handle`). A handle the user typed is left
     * untouched so a duplicate surfaces via {@see self::validateUniqueHandle()}
     * instead of being silently saved under a different value.
     */
    public function generateHandle(): void
    {
        if (!empty($this->handle) || empty($this->name)) {
            return;
        }

        $baseHandle = StringHelper::toHandle($this->name);

        if (empty($baseHandle)) {
            return;
        }

        $this->handle = $baseHandle;
        $suffix = 1;

        while ($this->handleExists($this->handle)) {
            $suffix++;
            $this->handle = $baseHandle . $suffix;
        }
    }

    /**
     * Whether a test other than this one already uses the given handle.
     *
     * Trashed tests are intentionally included because the unique DB index
     * applies to them too.
     */
    private function handleExists(string $handle): bool
    {
        $query = TestRecord::find()->where(['handle' => $handle]);

        if ($this->id) {
            $query->andWhere(['not', ['id' => $this->id]]);
        }

        return $query->exists();
    }

    /**
     * Get the control entry
     */
    public function getControlEntry(): ?Entry
    {
        if (!$this->controlEntryId) {
            return null;
        }
        return Entry::find()->id($this->controlEntryId)->siteId($this->siteId)->one();
    }

    /**
     * Get the variant entry
     */
    public function getVariantEntry(): ?Entry
    {
        if (!$this->variantEntryId) {
            return null;
        }
        return Entry::find()->id($this->variantEntryId)->siteId($this->siteId)->one();
    }

    /**
     * Check if test is running
     */
    public function isRunning(): bool
    {
        return $this->status === self::STATUS_RUNNING;
    }

    /**
     * Check if test is completed
     */
    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * Check if test has been soft deleted
     */
    public function isTrashed(): bool
    {
        return $this->dateDeleted !== null;
    }

    /**
     * Get the duration of the test in days
     * Returns null if test hasn't started or is still running
     */
    public function getDurationDays(): ?int
    {
        if (!$this->startedAt) {
            return null;
        }

        $endDate = $this->endedAt ?? new DateTime();
        $diff = $this->startedAt->diff($endDate);

        // Return at least 1 day if test ran for any amount of time
        return max(1, $diff->days);
    }

    /**
     * Check if test can be started (status only)
     */
    public function canStart(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_PAUSED]);
    }

    /**
     * Check if test has enabled goals configured
     */
    public function hasEnabledGoals(): bool
    {
        return !empty($this->getEnabledGoals());
    }

    /**
     * Check if test is ready to start (status + goals)
     */
    public function isReadyToStart(): bool
    {
        return $this->canStart() && $this->hasEnabledGoals();
    }

    /**
     * Check if test can be paused
     */
    public function canPause(): bool
    {
        return $this->status === self::STATUS_RUNNING;
    }

    /**
     * Get available goal types
     */
    public static function getGoalTypes(): array
    {
        return [
            self::GOAL_PHONE => 'Phone Click (tel: links)',
            self::GOAL_FORM => 'Form Submission',
            self::GOAL_PAGE => 'Page Visit',
            self::GOAL_EMAIL => 'Email Click (mailto: links)',
            self::GOAL_DOWNLOAD => 'File Download',
        ];
    }

    /**
     * Get available statuses
     */
    public static function getStatuses(): array
    {
        return [
            self::STATUS_DRAFT => 'Draft',
            self::STATUS_RUNNING => 'Running',
            self::STATUS_PAUSED => 'Paused',
            self::STATUS_COMPLETED => 'Completed',
        ];
    }

    /**
     * Get all goals for this test
     *
     * @return Goal[]
     */
    public function getGoals(): array
    {
        if (!$this->id) {
            return [];
        }

        return ABTestCraft::getInstance()->goals->getGoalsByTestId($this->id);
    }

    /**
     * Get enabled goals for this test
     *
     * @return Goal[]
     */
    public function getEnabledGoals(): array
    {
        if (!$this->id) {
            return [];
        }

        return ABTestCraft::getInstance()->goals->getEnabledGoalsByTestId($this->id);
    }

    /**
     * Check if test has any goals configured
     */
    public function hasGoals(): bool
    {
        return !empty($this->getGoals());
    }

    /**
     * Get goals configuration for JavaScript tracking
     */
    public function getGoalsJsConfig(): array
    {
        if (!$this->id) {
            return [];
        }

        return ABTestCraft::getInstance()->goals->getGoalsJsConfig($this->id);
    }
}
