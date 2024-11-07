function gestion_loadCriForm(action, modal, params) {
   var formInput;

   if (params.form != undefined) {
      formInput = getRpFormData($('form[name="' + params.form + '"]'));
   }

   $.ajax({
      url: params.root_doc + '/ajax/cri.php',
      type: "POST",
      dataType: "html",
      data: {
         'action': action,
         'params': params,
         'pdf_action': params.pdf_action,
         'formInput': formInput,
         'modal': modal
      },
      success: function (response, opts) {
         try {
            var json = $.parseJSON(response);
            if (!json.success) {
               $("#rp_cri_error").html(json.message).show().delay(2000).fadeOut('slow');
            }

         } catch (err) {
            $('#' + modal).html(response);

            switch (action) {
               case 'saveCri':
                  // Ferme le modal et recharge la page
                  window.location.reload();
                  break;
               default:
                  // Ouvre le modal et force le style no-scroll avec JavaScript
                  glpi_html_dialog({
                     title: __('Gestion BL / BC', 'gestion'),
                     body: response,
                     id: action,
                     afterOpen: function() {
                        // Forcer le style no-scroll sur le body
                        document.body.classList.add('no-scroll');
                        // Fixer les marges pour éviter le décalage horizontal
                        document.body.style.marginLeft = '0px';
                        document.body.style.marginRight = '0px';
                     },
                     afterClose: function() {
                        // Rétablit le défilement de la page principale
                        document.body.classList.remove('no-scroll');
                        document.body.style.marginLeft = '';
                        document.body.style.marginRight = '';
                     }
                  });
                  break;
            }
         }
      }
   });
}