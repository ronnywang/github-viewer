var main = {};

main.onload_user_tree = function(){
  $.get('https://api.github.com/repos/' + encodeURIComponent(main.params.user) + '/' + encodeURIComponent(main.params.repository) + '/contents/' + main.params.path, function(ret){
    $('#file-table').empty();
    var tr_dom;
    var type;
    for (var i = 0; i < ret.data.length; i ++) {
      tr_dom = $('<tr></tr>');
      if (ret.data[i].type == 'dir') {
        type = 'tree';
      } else {
        type = 'blob';
      }
      tr_dom.append($('<td></td>').append($('<a></a>').attr('href', '/' + encodeURIComponent(main.params.user) + '/' + encodeURIComponent(main.params.repository) + '/' + type + '/' + encodeURIComponent(main.params.branch) + '/' + ret.data[i].path).text(ret.data[i].name)));

      $('#file-table').append(tr_dom);
    }
  }, 'jsonp');
};

main.onload_user_blob = function(){
  $('#btn-import-csv').click(function(e){
    $(this).text($(this).attr('data-wording-importing'));

    $.post('/user/importcsv?user=' + encodeURIComponent(main.params.user) + '&repository=' + encodeURIComponent(main.params.repository) + '&path=' + encodeURIComponent(main.params.path), {}, function(ret){
        if (ret.error) {
          alert(ret.message);
          return;
        }
        document.location.reload();
    }, 'json');
  });

  if (!$('#blob-content').length) {
    return;
  }
  $.get('https://api.github.com/repos/' + encodeURIComponent(main.params.user) + '/' + encodeURIComponent(main.params.repository) + '/contents/' + main.params.path, function(ret){
    if (ret.data.size > 0 && ret.data.content == '') {
      $('#blob-content').text("(Sorry about that, but we can't show files that are this big right now.)");
    } else {
      $('#blob-content').text(Base64.decode(ret.data.content));
    }
  }, 'jsonp');
};

main.onload_user_index = function(){
  $.get('https://api.github.com/users/' + encodeURIComponent(main.params.user) + '/repos', function(ret){
    $('#repo-list').empty();
    var li_dom;
    for (var i = 0; i < ret.data.length; i ++){
       li_dom = $('<li></li>');
       li_dom.append($('<a></a>').attr('href', '/' + ret.data[i].full_name).text(ret.data[i].full_name));
       $('#repo-list').append(li_dom);
    }
  }, 'jsonp');
};

main.onload = function(){
  if ($('body').is('.user_index')){
    main.onload_user_index();
  }

  if ($('body').is('.user_tree')){
    main.onload_user_tree();
  }

  if ($('body').is('.user_blob')){
    main.onload_user_blob();
  }
};

$(main.onload);
