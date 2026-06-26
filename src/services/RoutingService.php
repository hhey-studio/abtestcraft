<?php

declare(strict_types=1);

namespace livehand\abtestcraft\services;

use Craft;
use craft\base\Component;
use craft\elements\db\EagerLoadPlan;
use craft\elements\Entry;
use craft\events\TemplateEvent;
use livehand\abtestcraft\models\Test;
use livehand\abtestcraft\ABTestCraft;

/**
 * Routing service - handles template/content swapping for split tests
 */
class RoutingService extends Component
{
    private ?Test $activeTest = null;
    private ?string $activeVariant = null;
    private bool $isCascaded = false;
    private ?array $cascadeInfo = null;
    private ?Entry $controlEntry = null;
    private ?Entry $variantParent = null;

    /**
     * Handle the before render event to swap content if needed
     */
    public function handleBeforeRender(TemplateEvent $event): void
    {
        // Only process front-end requests
        if (Craft::$app->getRequest()->getIsCpRequest()) {
            return;
        }

        // Get the matched element
        $element = Craft::$app->getUrlManager()->getMatchedElement();

        if (!$element instanceof Entry) {
            return;
        }

        // Check if there's an active test for this entry (direct match)
        $test = ABTestCraft::getInstance()->tests->getTestByControlEntryId(
            $element->id,
            $element->siteId
        );

        $this->isCascaded = false;
        $this->cascadeInfo = null;

        // If no direct test, check if this is a descendant of a tested entry (cascade)
        if (!$test || !$test->isRunning()) {
            $cascadeInfo = ABTestCraft::getInstance()->cascade->getCascadeInfo(
                $element->id,
                $element->siteId
            );

            if ($cascadeInfo) {
                $test = ABTestCraft::getInstance()->tests->getTestById($cascadeInfo['testId']);
                if ($test && $test->isRunning()) {
                    $this->isCascaded = true;
                    $this->cascadeInfo = $cascadeInfo;
                } else {
                    $test = null;
                }
            }
        }

        if (!$test || !$test->isRunning()) {
            return;
        }

        // Get or assign variant
        $variant = ABTestCraft::getInstance()->assignment->getOrAssignVariant($test);

        // Store for later use (tracking, SEO, Twig helpers)
        $this->activeTest = $test;
        $this->activeVariant = $variant;

        // Handle based on whether this is a direct test or cascaded
        if ($variant === Test::VARIANT_VARIANT) {
            if ($this->isCascaded) {
                // Cascaded page - visitor is on a child page of a tested parent
                $this->variantParent = Entry::find()
                    ->id($this->cascadeInfo['variantAncestorId'])
                    ->siteId($element->siteId)
                    ->status(null)
                    ->one();

                // Automatically override entry.parent to return variant parent
                // This makes {{ entry.parent }} work without template changes
                if ($this->variantParent) {
                    $this->overrideEntryParent($element, $this->variantParent);
                }
            } else {
                // Direct test - swap to variant entry
                $this->controlEntry = $element;
                $this->swapToVariant($event, $test);
            }
        }

        // Record impression
        ABTestCraft::getInstance()->tracking->recordImpression($test, $variant);
    }

    /**
     * Override entry's cached _parent property using Reflection
     * This makes {{ entry.parent }} automatically return the variant parent
     * without requiring any template changes
     */
    private function overrideEntryParent(Entry $entry, Entry $newParent): void
    {
        try {
            // Use Reflection to access the private _parent property
            // Craft's getParent() checks this cached property first
            $reflection = new \ReflectionClass(\craft\base\Element::class);
            $parentProperty = $reflection->getProperty('_parent');
            $parentProperty->setAccessible(true);
            $parentProperty->setValue($entry, $newParent);
        } catch (\ReflectionException $e) {
            Craft::warning(
                "Split Test: Could not override entry parent via Reflection: " . $e->getMessage(),
                __METHOD__
            );
        }
    }

    /**
     * Override entry's children by setting eager-loaded elements
     * This makes {{ entry.children }} automatically return control's children
     * without requiring any template changes
     */
    private function overrideEntryChildren(Entry $variantEntry, Entry $controlEntry): void
    {
        try {
            // Get the control entry's children
            $controlChildren = $controlEntry->getChildren()->all();

            if (empty($controlChildren)) {
                return;
            }

            // Use setEagerLoadedElements to override what getChildren() returns
            // This is the cleanest way as Craft checks eager-loaded elements first.
            // Craft 5 requires an EagerLoadPlan as the third argument.
            $plan = new EagerLoadPlan([
                'handle' => 'children',
                'alias' => 'children',
                'all' => true,
            ]);
            $variantEntry->setEagerLoadedElements('children', $controlChildren, $plan);
        } catch (\Exception $e) {
            Craft::warning(
                "Split Test: Could not override entry children: " . $e->getMessage(),
                __METHOD__
            );
        }
    }

    /**
     * Swap the template/content to the variant entry
     */
    private function swapToVariant(TemplateEvent $event, Test $test): void
    {
        $variantEntry = $test->getVariantEntry();
        $controlEntry = $this->controlEntry;

        if (!$variantEntry) {
            Craft::warning("Split Test: Variant entry not found for test '{$test->handle}'", __METHOD__);
            return;
        }

        // Get the variant's section template settings. Nested entries (Matrix/
        // CKEditor) have no section, so getSection() can return null.
        $section = $variantEntry->getSection();
        if (!$section) {
            Craft::warning("Split Test: Variant entry has no section for test '{$test->handle}'", __METHOD__);
            return;
        }
        $siteSettings = $section->getSiteSettings();
        $siteId = $variantEntry->siteId;

        if (isset($siteSettings[$siteId])) {
            $templatePath = $siteSettings[$siteId]->template;

            if ($templatePath) {
                // Swap template
                $event->template = $templatePath;
            }
        }

        // Automatically override entry.children to return control's children
        // This makes {{ entry.children }} work without template changes
        if ($controlEntry) {
            $this->overrideEntryChildren($variantEntry, $controlEntry);
        }

        // Swap the entry variable
        if (isset($event->variables['entry'])) {
            $event->variables['entry'] = $variantEntry;
        }

        // Also set as 'element' if that's used
        if (isset($event->variables['element'])) {
            $event->variables['element'] = $variantEntry;
        }
    }

    /**
     * Get the currently active test (if any)
     */
    public function getActiveTest(): ?Test
    {
        return $this->activeTest;
    }

    /**
     * Get the currently active variant (if any)
     */
    public function getActiveVariant(): ?string
    {
        return $this->activeVariant;
    }

    /**
     * Check if there's an active test on this request
     */
    public function hasActiveTest(): bool
    {
        return $this->activeTest !== null;
    }

    /**
     * Check if current visitor is seeing the variant
     */
    public function isShowingVariant(): bool
    {
        return $this->activeVariant === Test::VARIANT_VARIANT;
    }

    /**
     * Check if current visitor is seeing the control
     */
    public function isShowingControl(): bool
    {
        return $this->activeVariant === Test::VARIANT_CONTROL;
    }

    /**
     * Check if current page is a cascaded descendant of a tested entry
     */
    public function isCascaded(): bool
    {
        return $this->isCascaded;
    }

    /**
     * Get cascade info for the current page (if cascaded)
     */
    public function getCascadeInfo(): ?array
    {
        return $this->cascadeInfo;
    }

    /**
     * Get the variant parent entry for cascaded pages
     * Used by Twig helpers to return variant parent in place of control parent
     */
    public function getVariantParent(): ?Entry
    {
        return $this->variantParent;
    }

    /**
     * Get the control entry when showing variant
     * Used by Twig helpers to "borrow" children from control
     */
    public function getControlEntry(): ?Entry
    {
        return $this->controlEntry;
    }
}
