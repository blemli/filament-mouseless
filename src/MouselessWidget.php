<?php

namespace Blemli\FilamentMouseless;

use Blemli\FilamentMouseless\Filament\Widgets\StatisticsOverview;

/**
 * Public alias for the statistics card so dashboards read naturally:
 *
 *     ->widgets([MouselessWidget::class])
 *
 * It renders (and hides itself via canView()) exactly like the copy on
 * /my-shortcuts: clicks avoided, keyboard-share trend, untapped actions.
 */
class MouselessWidget extends StatisticsOverview {}
