<script>
    // when we click on radio button fill and show modal
    jQuery(".sc_payment_method_field").on("change", function() {
        if(jQuery(this).is(":checked")) {
            scGenerateAPMFields(jQuery(this).val());
        }
    });
    
    // when we close modal clear modal body and uncheck radio inputs
    jQuery(".close_apms_modal_btn").on('click', function(){
        jQuery('#apms_modal .modal-body').html('');
        jQuery('input.sc_payment_method_field').prop('checked', false);
    });
    
    function scGenerateAPMFields(methodName) {
       jQuery('#apms_modal .modal-body').html('');
        
        if(typeof paymentMethods[methodName] == 'undefined') {
            return;
        }
        
        // do not show modal beacuse we do not have fileds in it
        if(paymentMethods[methodName].length == 0) {
            return;
        }
        
        var html = '';
        for(var idx in paymentMethods[methodName]) {
            var required = '';
            var pattern = '';
            var requiredStar = '';
            
            if(typeof paymentMethods[methodName][idx].regex != 'undefined') {
                required = ' required="required"';
                pattern = 'pattern="'+ paymentMethods[methodName][idx].regex +'"';
                requiredStar = '&nbsp;<abbr class="required" title="required">*</abbr>';
            }
            
            html +=
                '<p class="form-row form-row-wide" id="'+ methodName +'_'+ paymentMethods[methodName][idx].name +'" data-o_class="form-row form-row-wide">'
                    +'<label>'+ paymentMethods[methodName][idx].caption[0].message + requiredStar +'</label>'
                    +'<span class="woocommerce-input-wrapper">'
                        +'<input class="input-text " name="'+ paymentMethods[methodName][idx].name +'" type="'+ paymentMethods[methodName][idx].type +'" '+ required +' '+ pattern +'>'
                    +'</span>'
                +'</p>';
        }
        
        jQuery('#apms_modal .modal-body').html(html);
        jQuery('#apms_modal').modal('toggle');
    }
    
    /**
     * Function validateScAPMsModal
     * When click save on modal, check for mandatory fields and validate them.
     */
    function validateScAPMsModal() {
        // TODO use jQuery Validate Plugin
    }
</script>