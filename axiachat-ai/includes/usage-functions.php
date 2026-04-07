<?php
if(!defined('ABSPATH')) exit;

/**
 * Pricing table (USD) per 1K tokens. Can be filtered.
 * cost_micros stored as integer micro-USD.
 *
 * Since 2.4.0 prices are derived from the centralised model registry
 * (includes/model-registry.php). The filter 'aichat_model_pricing' is
 * still applied so existing overrides keep working.
 */
function aichat_model_pricing(){
  $pricing = aichat_registry_build_legacy_pricing();
  return apply_filters('aichat_model_pricing', $pricing);
}

function aichat_calc_cost_micros($provider,$model,$prompt_tokens,$completion_tokens){
  $pricing = aichat_model_pricing();
  $prov = strtolower((string)$provider);
  $m = strtolower((string)$model);
  if(!isset($pricing[$prov])) return null;
  // intentar match exacto; si no, fallback aproximado por prefijo
  $entry = null;
  if(isset($pricing[$prov][$m])){ $entry = $pricing[$prov][$m]; }
  else {
    foreach($pricing[$prov] as $k=>$v){ if(stripos($m,$k)===0){ $entry=$v; break; } }
  }
  if(!$entry) return null;
  $in = max(0,(int)$prompt_tokens); $out = max(0,(int)$completion_tokens);
  $cost = ($in/1000.0)*$entry['input_per_1k'] + ($out/1000.0)*$entry['output_per_1k'];
  return (int)round($cost * 1000000); // micro-USD
}

/** Update / upsert daily aggregate */
function aichat_update_daily_usage_row($provider,$model,$prompt,$completion,$total,$cost_micros){
  global $wpdb; $table = $wpdb->prefix.'aichat_usage_daily';
  $date = current_time('Y-m-d');
  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Internal aggregate upsert.
  $wpdb->query( $wpdb->prepare(
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a trusted plugin table name.
    "INSERT INTO $table (date,provider,model,prompt_tokens,completion_tokens,total_tokens,cost_micros,conversations)
      VALUES (%s,%s,%s,%d,%d,%d,%d,1)
      ON DUPLICATE KEY UPDATE
        prompt_tokens = prompt_tokens + VALUES(prompt_tokens),
        completion_tokens = completion_tokens + VALUES(completion_tokens),
        total_tokens = total_tokens + VALUES(total_tokens),
        cost_micros = cost_micros + VALUES(cost_micros),
        conversations = conversations + 1",
    $date,$provider,$model,$prompt,$completion,$total,$cost_micros
  ) );
}

/**
 * Check whether budget / cost limits have been exceeded.
 *
 * @return array{exceeded:bool,behavior:string} 'behavior' is 'hide' or 'whatsapp'.
 */
function aichat_is_budget_exceeded() {
    if ( ! get_option( 'aichat_usage_limits_enabled', 1 ) ) {
        return [ 'exceeded' => false, 'behavior' => 'hide' ];
    }

    $cost_daily_tokens   = (int) get_option( 'aichat_cost_limit_daily_tokens', 0 );
    $cost_daily_usd      = (float) get_option( 'aichat_cost_limit_daily_usd', 0 );
    $cost_monthly_tokens = (int) get_option( 'aichat_cost_limit_monthly_tokens', 0 );
    $cost_monthly_usd    = (float) get_option( 'aichat_cost_limit_monthly_usd', 0 );

    $has_any = ( $cost_daily_tokens > 0 || $cost_daily_usd > 0 || $cost_monthly_tokens > 0 || $cost_monthly_usd > 0 );
    if ( ! $has_any ) {
        return [ 'exceeded' => false, 'behavior' => 'hide' ];
    }

    global $wpdb;
    $conv_table = $wpdb->prefix . 'aichat_conversations';
    $exceeded   = false;

    // Daily checks
    if ( $cost_daily_tokens > 0 || $cost_daily_usd > 0 ) {
        $today_start = gmdate( 'Y-m-d 00:00:00' );
        $today_end   = gmdate( 'Y-m-d 23:59:59' );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $conv_table is a trusted plugin table name.
        $daily = $wpdb->get_row( $wpdb->prepare(
            "SELECT COALESCE(SUM(total_tokens),0) AS tokens, COALESCE(SUM(cost_micros),0) AS cost_micros FROM {$conv_table} WHERE created_at BETWEEN %s AND %s",
            $today_start, $today_end
        ), ARRAY_A );
        if ( $cost_daily_tokens > 0 && (int) $daily['tokens'] >= $cost_daily_tokens ) {
            $exceeded = true;
        }
        if ( ! $exceeded && $cost_daily_usd > 0 && ( (int) $daily['cost_micros'] / 1000000 ) >= $cost_daily_usd ) {
            $exceeded = true;
        }
    }

    // Monthly checks
    if ( ! $exceeded && ( $cost_monthly_tokens > 0 || $cost_monthly_usd > 0 ) ) {
        $month_start = gmdate( 'Y-m-01 00:00:00' );
        $month_end   = gmdate( 'Y-m-t 23:59:59' );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $conv_table is a trusted plugin table name.
        $monthly = $wpdb->get_row( $wpdb->prepare(
            "SELECT COALESCE(SUM(total_tokens),0) AS tokens, COALESCE(SUM(cost_micros),0) AS cost_micros FROM {$conv_table} WHERE created_at BETWEEN %s AND %s",
            $month_start, $month_end
        ), ARRAY_A );
        if ( $cost_monthly_tokens > 0 && (int) $monthly['tokens'] >= $cost_monthly_tokens ) {
            $exceeded = true;
        }
        if ( ! $exceeded && $cost_monthly_usd > 0 && ( (int) $monthly['cost_micros'] / 1000000 ) >= $cost_monthly_usd ) {
            $exceeded = true;
        }
    }

    $behavior = get_option( 'aichat_cost_limit_behavior', 'hide' );
    return [ 'exceeded' => $exceeded, 'behavior' => $behavior ];
}
