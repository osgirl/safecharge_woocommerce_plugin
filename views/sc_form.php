<!-- we do not use this view at the moment -->
<form action="<?= self::URL ?>" method="post" id="sc_payment_form">
    <?php implode('', $params_array); ?>
    <noscript>
        <input type="submit" class="button-alt" id="submit_sc_payment_form" value="<?php __('Pay via '.SC_GATEWAY_TITLE, 'sc') ?>" />'
        <a class="button cancel" href="<?php $order->get_cancel_order_url() ?>"><?php __('Cancel order &amp; restore cart', 'sc') ?></a>
    </noscript>
    
    <script type="text/javascript">
        jQuery(function() {
            <?php if(isset($this->show_thanks_msg) && $this->show_thanks_msg == 'yes'): ?>
                jQuery("body").block({
                    message: '<img src="<?= $this->plugin_url ?>/icons/loading.gif" alt="Redirecting" style="width:100px; float:left; margin-right: 10px;" /><?php __('Thank you for your order. We are now redirecting you to '.SC_GATEWAY_TITLE.' Payment Gateway to make payment.', 'sc') ?>'
                    ,overlayCSS: {
                        background: "#fff",
                        opacity: 0.6
                    }
                    ,css: {
                        padding:        20,
                        textAlign:      "center",
                        color:          "#555",
                        border:         "3px solid #aaa",
                        backgroundColor:"#fff",
                        cursor:         "wait",
                        lineHeight:     "32px"
                    }
                });
            <?php endif; ?>
            
            jQuery("#sc_payment_form").submit();
        });
    </script>
</form>