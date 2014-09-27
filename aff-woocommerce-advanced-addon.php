<?php

/**
 * Plugin Name: Affiliates WooCommerce Advanced Integration
 * Plugin URI: https://www.tipsandtricks-hq.com/wordpress-affiliate-platform-plugin-simple-affiliate-program-for-wordpress-blogsite-1474
 * Description: Addon for using advanced WooCommerce integration options with the affiliate platform plugin
 * Version: 1.2
 * Author: Tips and Tricks HQ
 * Author URI: https://www.tipsandtricks-hq.com/
 * Requires at least: 3.0
 */
if (!defined('ABSPATH'))
    exit;

//Add the meta box in the woocommerce product add/edit interface
add_action('add_meta_boxes', 'aff_woo_advanced_meta_boxes');

function aff_woo_advanced_meta_boxes() {
    add_meta_box('aff-woo-advanced-product-data', 'WP Affiliate Platform Settings', 'aff_woo_advanced_data_box', 'product', 'normal', 'high');
}

function aff_woo_advanced_data_box($wp_post_obj) {
    $commission_level = get_post_meta($wp_post_obj->ID, 'aff_woo_product_specific_commission', true);
    echo "Commission Level: ";
    echo '<input type="text" size="5" name="aff_woo_product_specific_commission" value="' . $commission_level . '" />';
    echo '<p>Product specific commission level for this product (example value: 25). Only enter the number (do not use "%" or "$" sign).</p>';
}

//Save the membership level data to the post meta with the product when it is saved
add_action('save_post', 'aff_woo_advanced_save_product_data', 10, 2);

function aff_woo_advanced_save_product_data($post_id, $post_obj) {
    // Check post type for woocommerce product
    if ($post_obj->post_type == 'product') {
        // Store data in post meta table if present in post data
        if (isset($_POST['aff_woo_product_specific_commission'])) {
            update_post_meta($post_id, 'aff_woo_product_specific_commission', $_POST['aff_woo_product_specific_commission']);
        }
    }
}

add_filter('wp_aff_award_commission_override_filter', 'woo_advanced_handle_woocommerce_comm_override', 10, 2);

function woo_advanced_handle_woocommerce_comm_override($override, $data) {
    $referrer = $data['referrer'];
    $order_id_data = $data['txn_id'];
    $pieces = explode("_", $order_id_data);
    $order_id = $pieces[0];
    wp_affiliate_log_debug('Woo Advanced - debug data: ' . $order_id . '|' . $referrer, true);

    global $wpdb;
    $affiliates_table_name = $wpdb->prefix . "affiliates_tbl";
    $result = $wpdb->get_row("SELECT * FROM $affiliates_table_name WHERE refid = '$referrer'", OBJECT);

    $commission_level = $result->commissionlevel;
    $second_tier_referrer = $result->referrer;
    $second_tier_commission_level = 0;
    if (!empty($second_tier_referrer)) {//This affiliate has a 2nd tier referrer
        wp_affiliate_log_debug('Woo Advanced - Retrieving the 2nd tier affiliate profile.', true);
        $second_tier_aff = $wpdb->get_row("SELECT * FROM $affiliates_table_name WHERE refid = '$second_tier_referrer'", OBJECT);
        if (!empty($second_tier_aff->sec_tier_commissionlevel)) {
            $second_tier_commission_level = $second_tier_aff->sec_tier_commissionlevel;
            wp_affiliate_log_debug('Woo Advanced - The 2nd tier affiliate (' . $second_tier_referrer . ') has a profile specific 2nd tier commission level. Commission level is: ' . $second_tier_commission_level, true);
        } else {
            $second_tier_commission_level = get_option('wp_aff_2nd_tier_commission_level');
        }
    }

    $product_comm_amount = 0;
    $product_second_tier_comm_amt = 0;
    $total_commission_amount = 0;
    $total_t2_commission_amount = 0;

    $order = new WC_Order($order_id);
    $order_items = $order->get_items();
    $sale_amt = $order->order_total;

    foreach ($order_items as $item_id => $item) {
        if ($item['type'] == 'line_item') {
            $_product = $order->get_product_from_item($item);
            $post_id = $_product->id;
            $p_comm_level = get_post_meta($post_id, 'aff_woo_product_specific_commission', true);
            $p_t2_comm_level = ""; //get_post_meta( $post_id, 'aff_woo_product_specific_commission_t2', true );//TODO - add later

            $line_subtotal = $item['line_subtotal']; //(Price per unit * qty)
            $item_qty = $item['qty'];

            if ($p_comm_level == "0") {
                //== Product specific commisison override to 0. No commisison for this product.
                $product_comm_amount = 0;
                $product_second_tier_comm_amt = 0;
            } else if (is_numeric($p_comm_level)) {
                //== Calculate product specific commision for this product ==
                wp_affiliate_log_debug('Woo Advanced - This product has a product specific commisison rate specified for it.', true);
                if (get_option('wp_aff_use_fixed_commission')) {
                    //using fixed commission rate model
                    $product_comm_amount = $item_qty * $p_comm_level;
                    //Award fixed commission for 2nd tier from the product's specified level
                    if (is_numeric($p_t2_comm_level)) {
                        $product_second_tier_comm_amt = $item_qty * $p_t2_comm_level;
                    }
                } else {
                    //using % commission model
                    //The total item price includes the (individual item price * quantity)
                    $product_comm_amount = ($line_subtotal * $p_comm_level / 100);
                    //Award % commission for 2nd tier from the product's specified level
                    if (is_numeric($p_t2_comm_level)) {
                        $product_second_tier_comm_amt = $line_subtotal * ($p_t2_comm_level) / 100;
                    }
                }
            } else {
                //== Calculate commission based on affiliate profile ==
                wp_affiliate_log_debug('Woo Advanced - Using commission rate from affiliate profile', true);
                if (get_option('wp_aff_use_fixed_commission')) {
                    wp_affiliate_log_debug('Woo Advanced - Using fixed commission rate for this commission. Qty:' . $item_qty . ', Fixed commission level:' . $commission_level, true);
                    //Give fixed commission from the affiliate's specified level
                    $product_comm_amount = $item_qty * $commission_level;
                    //Award fixed commission for 2nd tier from the affiliate's specified level
                    $product_second_tier_comm_amt = $item_qty * $second_tier_commission_level;
                } else {
                    wp_affiliate_log_debug('Woo Advanced - Using % based commission rate for this commission. Qty:' . $item_qty . ', Total item price:' . $line_subtotal . ', Commission level:' . $commission_level, true);
                    //The total item price includes the (individual item price * quantity)
                    $product_comm_amount = $line_subtotal * ($commission_level / 100);
                    //Award fixed commission for 2nd tier from the affiliate's specified level
                    $product_second_tier_comm_amt = $line_subtotal * (($second_tier_commission_level) / 100);
                }
            }

            $total_commission_amount = $total_commission_amount + $product_comm_amount;
            $total_t2_commission_amount = $total_t2_commission_amount + $product_second_tier_comm_amt;
        }
    }//End of foreach
    //echo "<br />Total Commission amt: ".$total_commission_amount;
    //echo "<br />Total Commission amt t2: ".$total_t2_commission_amount;

    $override = "Commission overriden by Woo Advanced addon.";
    if ($total_commission_amount <= 0) {
        wp_affiliate_log_debug('Woo Advanced - The total commission amount is 0 for this transaction so nothing will be awarded.', true);
        return $override;
    }

    //Round up the amounts
    $total_commission_amount = round($total_commission_amount, 0);
    $total_t2_commission_amount = round($total_t2_commission_amount, 0);
    
    //Process primary commission    
    $fields = array();
    $fields['refid'] = $referrer;
    $fields['payment'] = $total_commission_amount;
    $fields['sale_amount'] = $sale_amt;
    $fields['txn_id'] = $order_id_data;
    //$fields['item_id'] = $item_id;
    $fields['buyer_email'] = $order->billing_email;
    $fields['buyer_name'] = $order->billing_first_name . " " . $order->billing_last_name;
    wp_aff_add_commission_amt_directly($fields);
    wp_affiliate_log_debug('Woo Advanced - direct commission award function processed for primary affiliate ('.$referrer.'). Commission amount: '.$total_commission_amount, true);
    
    //Process 2nd tier commission
    if(!empty($second_tier_referrer)){
        $t2fields = array();
        $t2fields['refid'] = $second_tier_referrer;
        $t2fields['payment'] = $total_t2_commission_amount;
        $t2fields['sale_amount'] = $sale_amt;
        $t2fields['txn_id'] = $order_id_data;
        //$fields['item_id'] = $item_id;
        $t2fields['buyer_email'] = $order->billing_email;
        $t2fields['buyer_name'] = $order->billing_first_name . " " . $order->billing_last_name;    
        $t2fields['is_tier_comm'] = "yes";
        wp_aff_add_commission_amt_directly($t2fields);
        wp_affiliate_log_debug('Woo Advanced - direct commission award function processed for 2nd tier affiliate ('.$second_tier_referrer.'). 2nd Tier commission amount: '.$total_t2_commission_amount, true);
    }
    
    return $override;
}

