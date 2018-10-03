<script>
    jQuery(".sc_payment_method_field").on("change", function() {
        if(jQuery(this).is(":checked")) {
            scGenerateAPMFields(jQuery(this).val());
        }
    });
    
    function scGenerateAPMFields(methodName) {
       jQuery('#apms_modal .modal-body').html('');
        
        if(typeof paymentMethods[methodName] == 'undefined') {
            return;
        }
        
        if(paymentMethods[methodName].length == 0) {
            // TODO send form...
            return;
        }
        
        var html = '';
        for(var idx in paymentMethods[methodName]) {
            var required = '';
            var pattern = '';
            if(typeof paymentMethods[methodName][idx].regex != 'undefined') {
                required = ' required="required"';
                pattern = 'pattern="'+ paymentMethods[methodName][idx].regex +'"';
            }
            
            html +=
                '<p class="form-row form-row-wide" id="'+ methodName +'_'+ paymentMethods[methodName][idx].name +'" data-o_class="form-row form-row-wide">'
                    +'<label>'+ paymentMethods[methodName][idx].caption[0].message +'&nbsp;</label>'
                    +'<span class="woocommerce-input-wrapper">'
                        +'<input class="input-text " name="'+ paymentMethods[methodName][idx].name +'" type="'+ paymentMethods[methodName][idx].type +'" '+ required +' '+ pattern +'>'
                    +'</span>'
                +'</p>';
        }
        
        jQuery('#apms_modal .modal-body').html(html);
        jQuery('#apms_modal').modal('toggle');
    }
</script>