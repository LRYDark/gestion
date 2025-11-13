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
                  // Capture l'élément déclencheur pour restaurer le focus à la fermeture
                  var openerEl = document.activeElement || null;
                  window.__gestionModalFocusMap = window.__gestionModalFocusMap || {};
                  window.__gestionModalFocusMap[action] = openerEl;
                  glpi_html_dialog({
                     title: __('Gestion BL', 'gestion'),
                     body: response,
                     id: action,
                     afterOpen: function() {
                        // Forcer le style no-scroll sur le body
                        document.body.classList.add('no-scroll');
                        // Fixer les marges pour éviter le décalage horizontal
                        document.body.style.marginLeft = '0px';
                        document.body.style.marginRight = '0px';

                        // A11y: gǸrer aria-hidden/inert et focus pour le modal Bootstrap
                        var $m = $('#' + action);
                        if ($m && $m.length) {
                           var modalEl = $m[0];
                           // Lever aria-hidden/inert à l'ouverture
                           modalEl.removeAttribute('aria-hidden');
                           modalEl.removeAttribute('inert');

                           // Placer un focus initial raisonnable (bouton de fermeture si prǸsent)
                           setTimeout(function(){
                              var closeBtn = modalEl.querySelector('.btn-close, [data-bs-dismiss="modal"]');
                              if (closeBtn && typeof closeBtn.focus === 'function') { closeBtn.focus(); }
                              else if (typeof modalEl.focus === 'function') { modalEl.focus(); }
                           }, 0);

                           // Avant fermeture: dǸplacer le focus hors du modal
                           $m.on('hide.bs.modal', function(){
                              try {
                                 var ae = document.activeElement;
                                 if (ae && modalEl.contains(ae)) {
                                    var opener = (window.__gestionModalFocusMap || {})[action];
                                    if (opener && document.body.contains(opener) && typeof opener.focus === 'function') {
                                       opener.focus();
                                    } else if (document.body && typeof document.body.focus === 'function') {
                                       document.body.focus();
                                    }
                                 }
                              } catch(e) {}
                              modalEl.setAttribute('inert', '');
                           });

                           // Synchroniser aria-hidden/inert apr��s affichage/fermeture
                           $m.on('shown.bs.modal', function(){
                              modalEl.removeAttribute('aria-hidden');
                              modalEl.removeAttribute('inert');
                           });
                           $m.on('hidden.bs.modal', function(){
                              modalEl.setAttribute('aria-hidden', 'true');
                              modalEl.setAttribute('inert', '');
                           });
                        }
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
