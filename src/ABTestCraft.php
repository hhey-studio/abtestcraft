<?php

declare(strict_types=1);

namespace livehand\abtestcraft;

use Craft;
use craft\base\Plugin as BasePlugin;
use craft\base\Model;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\events\TemplateEvent;
use craft\events\ElementEvent;
use craft\events\MoveElementEvent;
use craft\services\Elements;
use craft\services\Structures;
use craft\services\UserPermissions;
use craft\web\UrlManager;
use craft\web\View;
use livehand\abtestcraft\models\Settings;
use craft\elements\Entry;
use craft\web\twig\variables\CraftVariable;
use livehand\abtestcraft\services\AssignmentService;
use livehand\abtestcraft\services\AuditService;
use livehand\abtestcraft\services\CascadeService;
use livehand\abtestcraft\services\FormPluginDetectorService;
use livehand\abtestcraft\services\GoalsService;
use livehand\abtestcraft\services\NotificationService;
use livehand\abtestcraft\services\RoutingService;
use livehand\abtestcraft\services\SeoService;
use livehand\abtestcraft\services\StatsService;
use livehand\abtestcraft\services\TestsService;
use livehand\abtestcraft\services\TrackingService;
use livehand\abtestcraft\services\LicenseService;
use livehand\abtestcraft\variables\ABTestCraftVariable;
use craft\helpers\Cp;
use craft\helpers\UrlHelper;
use craft\events\RegisterCpAlertsEvent;
use yii\base\Event;

/**
 * ABTestCraft plugin for CraftCMS 5
 * A/B testing for CraftCMS - test page variations and track conversions
 *
 * @author Livehand Inc.
 * @property Settings $settings
 * @property TestsService $tests
 * @property GoalsService $goals
 * @property AssignmentService $assignment
 * @property RoutingService $routing
 * @property TrackingService $tracking
 * @property StatsService $stats
 * @property SeoService $seo
 * @property CascadeService $cascade
 * @property FormPluginDetectorService $formPluginDetector
 * @property NotificationService $notifications
 * @property AuditService $audit
 * @property LicenseService $license
 */
class ABTestCraft extends BasePlugin
{
    public string $schemaVersion = '1.4.0';
    public bool $hasCpSettings = true;
    public bool $hasCpSection = true;

    public static function config(): array
    {
        return [
            'components' => [
                'tests' => TestsService::class,
                'goals' => GoalsService::class,
                'assignment' => AssignmentService::class,
                'routing' => RoutingService::class,
                'tracking' => TrackingService::class,
                'stats' => StatsService::class,
                'seo' => SeoService::class,
                'cascade' => CascadeService::class,
                'formPluginDetector' => FormPluginDetectorService::class,
                'notifications' => NotificationService::class,
                'audit' => AuditService::class,
                'license' => LicenseService::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        // Register console commands
        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            $this->controllerNamespace = 'livehand\\abtestcraft\\console\\controllers';
        }

        // Defer most setup tasks until Craft is fully initialized
        Craft::$app->onInit(function () {
            $this->attachEventHandlers();
        });
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->view->renderTemplate('abtestcraft/settings', [
            'plugin' => $this,
            'settings' => $this->getSettings(),
            'licenseInfo' => $this->license->getStatusInfo(),
        ]);
    }

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $item['label'] = 'ABTestCraft';
        $item['subnav'] = [
            'tests' => ['label' => 'Tests', 'url' => 'abtestcraft/tests'],
            'settings' => ['label' => 'Settings', 'url' => 'settings/plugins/abtestcraft'],
        ];
        return $item;
    }

    private function attachEventHandlers(): void
    {
        // Register license alerts in CP
        if (Craft::$app->getRequest()->getIsCpRequest()) {
            Event::on(
                Cp::class,
                Cp::EVENT_REGISTER_ALERTS,
                function (RegisterCpAlertsEvent $event) {
                    $this->registerLicenseAlert($event);
                }
            );
        }

        // Register user permissions
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            function (RegisterUserPermissionsEvent $event) {
                $event->permissions[] = [
                    'heading' => 'ABTestCraft',
                    'permissions' => [
                        'abtestcraft:manageTests' => [
                            'label' => 'Manage split tests',
                        ],
                        'abtestcraft:viewResults' => [
                            'label' => 'View test results',
                        ],
                    ],
                ];
            }
        );

        // Register Twig variable
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            function (Event $event) {
                $event->sender->set('abtestcraft', ABTestCraftVariable::class);
            }
        );

        // Register CP routes
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function (RegisterUrlRulesEvent $event) {
                $event->rules['abtestcraft'] = 'abtestcraft/tests/index';
                $event->rules['abtestcraft/tests'] = 'abtestcraft/tests/index';
                $event->rules['abtestcraft/tests/new'] = 'abtestcraft/tests/new';
                $event->rules['abtestcraft/tests/<testId:\d+>'] = 'abtestcraft/tests/edit';
                $event->rules['abtestcraft/tests/<testId:\d+>/results'] = 'abtestcraft/tests/results';
            }
        );

        // Only register front-end handlers for site requests
        if (Craft::$app->getRequest()->getIsSiteRequest()) {
            // Handle template swapping before render
            Event::on(
                View::class,
                View::EVENT_BEFORE_RENDER_PAGE_TEMPLATE,
                function (TemplateEvent $event) {
                    // Never let A/B-test logic take down an unrelated front-end
                    // page. Any failure here degrades to the control experience.
                    try {
                        $this->routing->handleBeforeRender($event);

                        // Inject SEO protection for variant entries
                        $element = Craft::$app->getUrlManager()->getMatchedElement();
                        if ($element instanceof Entry) {
                            $this->seo->injectSeoProtection($element);
                        }
                    } catch (\Throwable $e) {
                        Craft::error('ABTestCraft beforeRender failed: ' . $e->getMessage(), __METHOD__);
                    }
                }
            );

            // Inject tracking script after render
            Event::on(
                View::class,
                View::EVENT_AFTER_RENDER_PAGE_TEMPLATE,
                function (TemplateEvent $event) {
                    try {
                        $this->injectTrackingScript($event);
                    } catch (\Throwable $e) {
                        Craft::error('ABTestCraft afterRender failed: ' . $e->getMessage(), __METHOD__);
                    }
                }
            );
        }

        // Register structure change handlers for cascade synchronization
        $this->registerCascadeEventHandlers();
    }

    /**
     * Register event handlers for keeping cascade mappings in sync with entry structure changes
     */
    private function registerCascadeEventHandlers(): void
    {
        // Handle entries being moved in structure
        Event::on(
            Structures::class,
            Structures::EVENT_AFTER_MOVE_ELEMENT,
            function (MoveElementEvent $event) {
                if ($event->element instanceof Entry) {
                    $this->cascade->handleEntryMoved($event->element);
                }
            }
        );

        // Handle entries being inserted into structure
        Event::on(
            Structures::class,
            Structures::EVENT_AFTER_INSERT_ELEMENT,
            function (MoveElementEvent $event) {
                if ($event->element instanceof Entry) {
                    $this->cascade->handleEntryMoved($event->element);
                }
            }
        );

        // Handle entry deletions
        Event::on(
            Elements::class,
            Elements::EVENT_AFTER_DELETE_ELEMENT,
            function (ElementEvent $event) {
                if ($event->element instanceof Entry) {
                    $this->cascade->handleEntryDeleted($event->element->id);
                }
            }
        );
    }

    /**
     * Inject tracking JavaScript into the page
     */
    private function injectTrackingScript(TemplateEvent $event): void
    {
        // Only inject if there's an active test
        if (!$this->routing->hasActiveTest()) {
            return;
        }

        $test = $this->routing->getActiveTest();
        $variant = $this->routing->getActiveVariant();
        $settings = $this->getSettings();

        // Get goals configuration for JavaScript
        $goalsConfig = $test->getGoalsJsConfig();

        // Build configuration for JavaScript
        $config = [
            'testHandle' => $test->handle,
            'variant' => $variant,
            'trackingEndpoint' => '/actions/abtestcraft/track/convert',
            'csrfTokenName' => Craft::$app->getConfig()->getGeneral()->csrfTokenName,
            'csrfToken' => Craft::$app->getRequest()->getCsrfToken(),
            'trackPhoneClicks' => $settings->trackPhoneClicks,
            'trackEmailClicks' => $settings->trackEmailClicks,
            'trackFormSubmissions' => $settings->trackFormSubmissions,
            'trackFileDownloads' => $settings->trackFileDownloads,
            'enableDataLayer' => $settings->enableDataLayer,
            // New multi-goal configuration
            'goals' => $goalsConfig,
            // Keep legacy fields for backward compatibility
            'goalType' => $test->goalType,
            'goalValue' => $test->goalValue,
        ];

        // Use safe JSON encoding to prevent XSS
        $configJson = json_encode($config, JSON_HEX_TAG | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_APOS);

        // Get tracking script content with proper error handling
        $trackingJsPath = __DIR__ . '/web/assets/tracking/tracking.js';
        try {
            if (!file_exists($trackingJsPath)) {
                throw new \RuntimeException("tracking.js not found at: {$trackingJsPath}");
            }
            $trackingJs = file_get_contents($trackingJsPath);
            if ($trackingJs === false) {
                throw new \RuntimeException("Failed to read tracking.js");
            }
        } catch (\Throwable $e) {
            Craft::error('ABTestCraft tracking script error: ' . $e->getMessage(), __METHOD__);
            return;
        }

        // Build the injection script
        $script = "<script>window.ABTestCraftConfig = {$configJson};</script>\n";
        $script .= "<script>{$trackingJs}</script>\n";

        // Inject before </body> - use strrpos to only replace the last occurrence
        $pos = strrpos($event->output, '</body>');
        if ($pos !== false) {
            $event->output = substr_replace($event->output, $script . '</body>', $pos, 7);
        }
    }

    /**
     * Register license alert in the control panel
     */
    private function registerLicenseAlert(RegisterCpAlertsEvent $event): void
    {
        $licenseInfo = $this->license->getStatusInfo();

        // Only show alert for trial or issue states
        if ($licenseInfo['message'] === null) {
            return;
        }

        $message = $licenseInfo['message'];

        // Add link to Plugin Store for trial
        if ($this->license->isTrial()) {
            $buyUrl = UrlHelper::url('plugin-store/abtestcraft');
            $message .= ' <a class="go" href="' . $buyUrl . '">' .
                Craft::t('abtestcraft', 'Buy now') . '</a>';
        }

        $event->alerts[] = $message;
    }
}
