jQuery(function($){
  const $rows = $('#cptt-wc-project-rows');
  const tpl = $('#cptt-wc-project-row-template').html() || '';

  function reindex(){
    $rows.find('.cptt-wc-projectRow').each(function(i){
      $(this).find('input, select, textarea').each(function(){
        const $el = $(this);
        const name = $el.attr('name');
        if (!name) return;
        $el.attr('name', name.replace(/cptt_wc_projects\[[^\]]+\]/, 'cptt_wc_projects[' + i + ']'));
      });
    });
  }

  $('#cptt-wc-add-project-row').on('click', function(){
    const i = $rows.find('.cptt-wc-projectRow').length;
    $rows.append(tpl.replaceAll('__i__', i));
    reindex();
  });

  $rows.on('click', '.cptt-wc-remove-project-row', function(){
    $(this).closest('.cptt-wc-projectRow').remove();
    reindex();
  });
});
