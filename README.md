# ABTestCraft Plugin for Craft CMS

A/B testing for Craft CMS 5 - test page variations and track conversions with statistical significance.

**Website:** [abtestcraft.com](https://abtestcraft.com)

## Features

- **Server-Side Testing**: No page flicker. Visitors are assigned variants before the page renders
- **Entry-Based Variants**: Use Craft's native entry system - test entire pages, not just CSS tweaks
- **Multi-Goal Tracking**: Track multiple conversion types per test (forms, phone clicks, emails, downloads, page visits)
- **Statistical Significance**: Chi-squared test with configurable confidence threshold (80-99%)
- **Smart Form Tracking**: Native integration with Freeform and Formie plugins
- **GA4/GTM Integration**: Push events to dataLayer for analytics tracking
- **SEO Protection**: Automatic noindex/canonical tags on variant entries
- **Cascade Support**: Child pages inherit parent's variant assignment
- **Privacy-Friendly**: First-party cookies only, no external trackers

## Requirements

- Craft CMS 5.0.0 or later
- PHP 8.2 or later

## Installation

1. Install with Composer:

```bash
composer require livehand/abtestcraft
```

2. Install the plugin from the Craft Control Panel under **Settings > Plugins**, or via CLI:

```bash
./craft plugin/install abtestcraft
```

## Quick Start

### 1. Create Your Test Pages

Create two entries in Craft CMS:
- **Control**: Your original page
- **Variant**: The variation you want to test

### 2. Create a Test

1. Go to **ABTestCraft** in the control panel sidebar
2. Click **New Test**
3. Enter a name and select your control/variant entries
4. Configure your goals (what counts as a conversion)
5. Set your traffic split (default 50/50)
6. Click **Start Test** when ready

### 3. View Results

Results update in real-time as visitors convert. The plugin calculates:
- Conversion rates per variant
- Statistical significance (Chi-squared test)
- Confidence level
- Relative improvement percentage
- Estimated days to significance

## Goal Types

### Phone Click
Tracks clicks on `tel:` links.

### Email Click
Tracks clicks on `mailto:` links.

### Form Submission
Tracks form submissions with smart detection:
- **Smart Mode**: Auto-detects Freeform/Formie success events
- **Advanced Mode**: Configure CSS selectors for custom forms

### File Download
Tracks downloads by file extension (PDF, DOC, XLS, ZIP, etc.).

### Page Visit
Tracks visits to a specific URL. Match types: exact, starts with, contains.

### Custom Event
Track custom conversions via JavaScript:

```javascript
// In your template or JavaScript
window.ABTestCraft.trackConversion('purchase_complete');
```

## Configuration

Configure global settings in **ABTestCraft > Settings**:

| Setting | Default | Description |
|---------|---------|-------------|
| Cookie Duration | 30 days | How long visitor assignments persist |
| Track Phone Clicks | On | Automatically track tel: links |
| Track Email Clicks | On | Automatically track mailto: links |
| Track Form Submissions | On | Automatically track form submits |
| Track File Downloads | On | Automatically track file downloads |
| Conversion Rate Limit | 10/min | Max conversions per IP per test per minute |
| Conversion Counting | Per Goal Type | How to count multiple conversions |
| Significance Threshold | 95% | Confidence level to declare significance |
| Minimum Detectable Effect | 10% | Smallest relative improvement to detect |
| Enable dataLayer | Off | Push events to GA4/GTM |

## Twig Variables

Access split test information in your templates via `craft.abtestcraft`:

```twig
{# Check if there's an active test on this page #}
{% if craft.abtestcraft.hasActiveTest() %}
    {# Get current variant ('control' or 'variant') #}
    {% set variant = craft.abtestcraft.getActiveVariant() %}

    {# Conditional content based on variant #}
    {% if craft.abtestcraft.isShowingVariant() %}
        {# Variant-specific content #}
    {% endif %}
{% endif %}

{# For cascade support - get parent accounting for variant #}
{% set parent = craft.abtestcraft.getParent(entry) %}

{# Get children accounting for variant borrowing #}
{% set children = craft.abtestcraft.getChildren(entry) %}

{# Check if current page is a cascaded child #}
{% if craft.abtestcraft.isCascaded() %}
    ...
{% endif %}
```

## Permissions

The plugin registers two permissions:

- **Manage AB tests** (`abtestcraft:manageTests`): Create, edit, start, pause, complete, and delete tests
- **View test results** (`abtestcraft:viewResults`): View test statistics and results

## GA4/GTM Integration

When enabled, the plugin pushes events to `window.dataLayer`:

### Events

| Event | When Fired | Parameters |
|-------|------------|------------|
| `abtestcraft_impression` | Visitor sees test | `abtestcraft_name`, `abtestcraft_variant` |
| `abtestcraft_conversion` | Conversion recorded | `abtestcraft_name`, `abtestcraft_variant`, `conversion_type` |

### GTM Setup

1. Create a Custom Event trigger for `abtestcraft_conversion`
2. Create Data Layer Variables for each parameter
3. Create a GA4 Event tag with your event parameters
4. Create Custom Dimensions in GA4 for reporting

## API Endpoints

### Record Conversion

```
POST /actions/abtestcraft/track/convert
```

Parameters:
- `testHandle` (string, required): The test handle
- `conversionType` (string, required): phone, email, form, download, page, or custom
- `goalId` (int, optional): Specific goal ID

Requires CSRF token in request.

## Console Commands

```bash
# Rebuild cascade mappings (if entries were moved while plugin was disabled)
./craft abtestcraft/rebuild-cascade
```

## How It Works

1. **Visitor arrives**: Plugin checks if there's an active test for the requested URL
2. **Assignment**: Visitor is assigned to control or variant (persisted in cookie)
3. **Template swap**: If assigned to variant, the variant entry's template is rendered instead
4. **Tracking**: JavaScript tracks configured goals and sends conversions to the server
5. **Statistics**: Chi-squared test calculates statistical significance
6. **Notification**: Email sent when test reaches significance threshold

## Data Retention & Backup

### Database Tables

The plugin creates the following tables that should be included in your backup strategy:

| Table | Purpose | Growth Rate |
|-------|---------|-------------|
| `abtestcraft_tests` | Test configurations | Low (manual creation) |
| `abtestcraft_goals` | Goal configurations | Low (tied to tests) |
| `abtestcraft_visitors` | Visitor assignments | Medium (one per unique visitor per test) |
| `abtestcraft_conversions` | Conversion events | Medium (one per counted conversion) |
| `abtestcraft_daily_stats` | Aggregated statistics | Low (one row per day/variant/goal) |
| `abtestcraft_rate_limits` | Rate limiting cache | Self-cleaning (auto-purges old entries) |

### Data Retention

- **Visitor and conversion data**: Retained indefinitely until test is hard-deleted
- **Statistics**: Aggregated daily, raw impressions not stored
- **Completed tests**: Remain in database for historical reference
- **Trashed tests**: Soft-deleted, can be restored or permanently removed

### Archiving Old Data

For high-traffic sites, consider periodically:
1. Exporting completed test data to your analytics platform
2. Hard-deleting old tests via **ABTestCraft > Completed > Delete Permanently**
3. Monitoring the `abtestcraft_rate_limits` table size (auto-cleans but verify)

### Backup Recommendations

Include these tables in your database backups:
```sql
abtestcraft_tests
abtestcraft_goals
abtestcraft_visitors
abtestcraft_conversions
abtestcraft_daily_stats
```

The `abtestcraft_rate_limits` table is transient and doesn't need to be backed up.

## Best Practices

### Test Duration
- Run tests for at least 7 days to account for weekly traffic patterns
- Wait for statistical significance before declaring a winner
- Don't peek and stop early - this inflates false positive rate

### Traffic Requirements
Sample size needed depends on your baseline conversion rate and minimum detectable effect:
- 5% baseline, 10% MDE: ~3,000 visitors per variant
- 2% baseline, 10% MDE: ~8,000 visitors per variant

### Goals
- Track the metric that matters most to your business
- Enable one goal type per test for clearest results
- Use "Per Goal Type" counting for multi-goal tests

## Support

For bug reports and feature requests, please [open an issue](https://github.com/livehanddotcom/abtestcraft/issues).

## License

This plugin is licensed under the [Craft License](https://craftcms.github.io/license/).
