<?php

declare(strict_types=1);

namespace Drupal\metsis_drupal\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\views\Plugin\views\filter\FilterPluginBase;

/**
 * Hook implementations for Views filters summary display.
 */
class ViewsFiltersSummaryHooks {

  /**
   * Implements hook_views_filters_summary_info_alter().
   *
   * Customizes the filter summary display for METSIS custom filters.
   */
  #[Hook('views_filters_summary_info_alter')]
  public function viewsFiltersSummaryInfoAlter(array &$info, FilterPluginBase $filter): void {
    // Handle custom summary for bounding box filter.
    if ($filter->getPluginId() === 'metsis_filter_bbox') {
      $info['value'] = [];
      $coordinates = [];
      foreach (['minX', 'maxX', 'minY', 'maxY'] as $coordinate) {
        $value = $filter->value[$coordinate] ?? '';
        if (!is_numeric($value)) {
          return;
        }
        $coordinates[] = $value;
      }
      // Format each value: 3 decimals for floats, as-is for ints.
      $formatted_values = array_map(function ($v) {
        return is_float($v + 0) ? number_format((float) $v, 3, '.', '') : (string) $v;
      }, $coordinates);

      $info['value'][] = [
        'id' => 0,
        'raw' => 'bbox',
        'value' => ucfirst($filter->operator) . '(' . implode(', ', $formatted_values) . ')',
      ];
    }

    // Handle custom summary for date range filter.
    if ($filter->getPluginId() === 'metsis_filter_date_range' && !empty($filter->value)) {
      $min = $filter->value['min'] ?? '';
      $max = $filter->value['max'] ?? '';

      if ($min !== '' || $max !== '') {
        $date_range = '';
        if ($min !== '' && $max !== '') {
          $date_range = "$min to $max";
        }
        elseif ($min !== '') {
          $date_range = "from $min";
        }
        elseif ($max !== '') {
          $date_range = "to $max";
        }

        $info['value'][0] = [
          'id' => 0,
          'raw' => 'date_range',
          'value' => ucfirst($filter->operator) . '(' . $date_range . ')',
        ];
      }
    }
    if ($filter->getPluginId() === 'metsis_parent_filter' && !empty($filter->value)) {
      // Set the label for the parent/collection filter.
      $info['label'] = 'Parent/Collection filter';
    }
  }

  /**
   * Implements hook_views_filters_summary_filter_value_alter().
   *
   * Fixes the filter value population for METSIS custom filters.
   */
  #[Hook('views_filters_summary_filter_value_alter')]
  public function viewsFiltersSummaryFilterValueAlter(mixed &$value, FilterPluginBase $filter): void {
    if (in_array($filter->getPluginId(), ['metsis_filter_bbox', 'metsis_filter_date_range'], TRUE)) {
      $inputs = $filter->view->getExposedInput();
      $identifier = $filter->options['expose']['identifier'];
      $value = $inputs[$identifier] ?? NULL;
    }
  }

}
