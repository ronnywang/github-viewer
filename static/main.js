var main = {};

main.onload_user_import = function(){
  main.register_import_button();
};

main.onload_user_tree = function(){
  $.get('https://api.github.com/repos/' + encodeURIComponent(main.params.user) + '/' + encodeURIComponent(main.params.repository) + '/contents/' + main.params.path + '?ref=' + encodeURIComponent(main.params.branch), function(ret){
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
      tr_dom.append($('<td></td>').append($('<a></a>').attr('href', '/' + encodeURIComponent(main.params.user) + '/' + encodeURIComponent(main.params.repository) + '/' + type + '/' + encodeURIComponent(main.params.branch) + '/' + ret.data[i].path).text(ret.data[i].name + (ret.data[i].type == 'dir' ? '/' : ''))));

      $('#file-table').append(tr_dom);
    }
  }, 'jsonp');
};

main.map_is_showed = false;

main.register_import_button = function(){
  var check_importing = function(job_id){
    $.get('/user/getimportstatus?id=' + parseInt(job_id), function(ret){
      if (ret.status == 'not_found') {
        alert('Import job is not found');
        document.location.reload();
        return;
      }

      if (ret.status == 'waiting') {
        $('#btn-import-csv').text('Waiting');
      } else if (ret.status == 'importing') {
        var stage_status = ret.data.stage_status[ret.data.current_stage];
        if (stage_status[0] == 'error') {
          alert('Error: ' + stage_status[2]);
          document.location.reload();
          return;
        } else if (stage_status[0] == 'finish') {
          document.location.reload();
          return;
        }
        $('#btn-import-csv').text(stage_status[0] + ':' + stage_status[2]);
      }  
      setTimeout(function(){ check_importing(job_id); }, 3000);
    }, 'json');
  };

  $('#btn-import-csv').click(function(e){
    $(this).text($(this).attr('data-wording-importing'));

    $.post($(this).attr('data-import-link'), {}, function(ret){
        if (ret.error) {
          alert(ret.message);
          return;
        }
        var job_id = ret.id;
        check_importing(job_id);
    }, 'json');
  });

};

main.show_map = function(){
  var tile_width = 400;
  var tile_height = 400;

  var getBBoxFromTileZoom = function(tile, zoom){
    var projection = map.getProjection();
    var zpow = Math.pow(2, zoom);
    var ul = new google.maps.Point(
        tile.x * tile_width / zpow, 
        (tile.y + 1) * tile_height / zpow
        );
    var lr = new google.maps.Point(
        (tile.x + 1) * tile_width / zpow, 
        tile.y * tile_height / zpow
        );
    var ulw = projection.fromPointToLatLng(ul);
    var lrw = projection.fromPointToLatLng(lr);

    return [ulw.lng(), ulw.lat(), lrw.lng(), lrw.lat()];
  };

  var tile_set = [];
  var current_tile = 0;
  var tiles = {};

  var meter_set = {};
  var click_url_set = {};

  var map;

  var mapOptions = {
    streetViewControl: false,
    minZoom: 1
  };

  map = new google.maps.Map(document.getElementById('data-tab-map'), mapOptions);

  var clickable = false;

  google.maps.event.addListener(map, 'mousemove', function(e){
    var projection = map.getProjection();
    var zpow = Math.pow(2, map.getZoom());

    var point = projection.fromLatLngToPoint(e.latLng);
    var tile_x = Math.floor((point.x * zpow / tile_width));
    var tile_y = Math.floor((point.y * zpow / tile_height));
    if ('undefined' === typeof(tiles[tile_x + ',' + tile_y + ',' + map.getZoom()])) {
      return;
    }
    var x = Math.floor(point.x * zpow) % tile_width;
    var y = Math.floor(point.y * zpow) % tile_height;
    var data = tiles[tile_x + ',' + tile_y + ',' + map.getZoom()].getContext('2d').getImageData(x, y, 1, 1).data;
    if (data[3]) {
      map.setOptions({draggableCursor: 'pointer'});
      clickable = true;
    } else {
      map.setOptions({draggableCursor: null});
      clickable = false;
    }
  });
  google.maps.event.addListener(map, 'click', function(e){
    if (clickable) {
      click_event(e);
    }
  });

  var addTile = function(wms_set){
    for (var i = 0; i < wms_set.length; i ++) {
      var tab_id = wms_set[i][0];
      addCustomControl(tab_id, tile_set.length);
      tile_set.push(tab_id);
    }
    CoordMapType = function(){};
    CoordMapType.prototype.tileSize = new google.maps.Size(tile_width, tile_height);
    CoordMapType.prototype.maxZoom = 17;
    CoordMapType.prototype.getTile = function(coord, zoom, ownerDocument) {
      var bbox = getBBoxFromTileZoom(coord, zoom);
      var southWest = new google.maps.LatLng(bbox[1], bbox[0]);
      var northEast = new google.maps.LatLng(bbox[3], bbox[2]);

      var div_dom = $('<div></div>');
      var img = new Image;
      img.style.width = this.tileSize.width + 'px';
      img.style.height = this.tileSize.height + 'px';
      img.className = 'wms-tile-img';
      img.src_set = {};
      for (var i = 0; i < wms_set.length; i ++) {
        img.src_set[wms_set[i][0]] = wms_set[i][1] + '&BBox=' + bbox.join(',') + '&Width=' + tile_width + '&height=' + tile_height;
        if (wms_set[i].length > 2) {
          meter_set[i] = wms_set[i][2];
          click_url_set[i] = wms_set[i][3];
          if (i == current_tile) {
            meterDiv.src = wms_set[i][2];
            meterDiv.style.display = 'block';
          }
        }
      }
      img.src = img.src_set[tile_set[current_tile]];

      if ($('#data-tab-map').attr('data-opacity')) {
        img.style.opacity = parseFloat($('#data-tab-map').attr('data-opacity'));
      }

      if ($('#data-tab-map').attr('data-clickzone-url')) {
        var clickzone_url = $('#data-tab-map').attr('data-clickzone-url');
        clickzone_url += '&BBox=' + bbox.join(',') + '&Width=' + tile_width + '&height=' + tile_height;
        var clickzone_img = new Image;
        clickzone_img.crossOrigin = 'Anonymous';
        clickzone_img.src = clickzone_url;
        clickzone_img.onload = function(){
          var canvas = document.createElement('canvas');
          canvas.width = canvas.height = 400;
          canvas.getContext('2d').drawImage(clickzone_img, 0, 0);
          tiles[coord.x + ',' + coord.y + ',' + zoom] = canvas;
        };
      }
      return div_dom.width(this.tileSize.width).height(this.tileSize.height).append(img)[0];
    };

    CoordMapType.prototype.releaseTile = function(node){
    };

    CoordMapType.prototype.name = 'WMS';
    var coordinateMapType = new CoordMapType();
    map.overlayMapTypes.insertAt(0, coordinateMapType);
  }


  var matches = document.location.hash.match('#([0-9.]*),([0-9.]*),([0-9]*)');
  var zoom = 8;
  var lat = 23.9720;
  var lng = 120.9777;
  if (matches) {
    zoom = parseInt(matches[3]);
    lat = parseFloat(matches[1]);
    lng = parseFloat(matches[2]);
    myLatlng = new google.maps.LatLng(lat, lng);
    map.setCenter(myLatlng);
    map.setZoom(zoom);
  } else if ($('#data-tab-map').attr('data-boundary')) {
    var b = JSON.parse($('#data-tab-map').attr('data-boundary'));
    var southWest = new google.maps.LatLng(parseFloat(b.min_lat), parseFloat(b.min_lng));
    var northEast = new google.maps.LatLng(parseFloat(b.max_lat), parseFloat(b.max_lng));
    var bounds = new google.maps.LatLngBounds(southWest, northEast);
    map.fitBounds(bounds);
    map.panToBounds(bounds);
  } else {
    myLatlng = new google.maps.LatLng(lat, lng);
    map.setCenter(myLatlng);
    map.setZoom(zoom);
  }

  var controlDiv = document.createElement('div');
  var meterDiv = document.createElement('img');

  var controlUI_set = [];

  controlDiv.style.padding = '5px';
  meterDiv.style.width = '100px';
  meterDiv.style.height = '300px';
  meterDiv.style.display = 'none';

  map.controls[google.maps.ControlPosition.TOP_RIGHT].push(controlDiv);
  map.controls[google.maps.ControlPosition.BOTTOM_LEFT].push(meterDiv);

  var addCustomControl = function(name, id){
    // Set CSS for the control border.
    var controlUI = document.createElement('div');
    if (id == current_tile) {
      controlUI.style.backgroundColor = 'yellow';
    } else {
      controlUI.style.backgroundColor = 'white';
    }
    controlUI.style.borderStyle = 'solid';
    controlUI.style.borderWidth = '2px';
    controlUI.style.cursor = 'pointer';
    controlUI.style.textAlign = 'center';
    controlUI.title = name;
    controlUI.className = 'custom-control-ui';
    controlUI.data_id = id;
    controlUI_set[id] = controlUI;
    controlDiv.appendChild(controlUI);

    // Set CSS for the control interior.
    var controlText = document.createElement('div');
    controlText.style.fontFamily = 'Arial,sans-serif';
    controlText.style.fontSize = '12px';
    controlText.style.paddingLeft = '4px';
    controlText.style.paddingRight = '4px';
    $(controlText).text(name);
    controlUI.appendChild(controlText);

    // Setup the click event listeners: simply set the map to Chicago.
    google.maps.event.addDomListener(controlUI, 'click', function() {
      $('.custom-control-ui').css('background-color', 'white');
      $(controlUI_set[this.data_id]).css('background-color', 'yellow');
      current_tile = this.data_id;
      if (meter_url = meter_set[this.data_id]) {
        meterDiv.src = meter_url;
      }
      $('.wms-tile-img').each(function(){
        img = this;
        img.src = img.src_set[tile_set[current_tile]];
      });
    });
  };

  if ($('#data-tab-map').attr('data-wms-url')) {
    addTile([['WMS', $('#data-tab-map').attr('data-wms-url')]]);
  } else if ($('#data-tab-map').attr('data-wms-set')) {
    var wms_set = JSON.parse($('#data-tab-map').attr('data-wms-set'));
    addTile(wms_set);
  }


  var infowindow = new google.maps.InfoWindow({
  });
  var marker = new google.maps.Marker({
    map: map
  });

  var click_event = function(e){
   $.get(click_url_set[current_tile] + '&lat=' + e.latLng.lat() + '&lng=' + e.latLng.lng(), function(ret){
     var div_dom = $('<div></div>');
     div_dom.css({'width': '100%', 'max-height':'250px', 'overflow': 'scroll'});
     div_dom.empty();
     if (ret.error) {
       console.log(ret.message);
       infowindow.setMap(null);
       marker.setMap(null);
     } else {
       for (var i = 0; i < ret.columns.length; i ++) {
         li_dom = $('<div></div>');
         li_dom.text(ret.columns[i] + ':' + ret.values[i]);
         div_dom.append(li_dom);
       }
     }
     infowindow.setContent($('<div></div>').append(div_dom).html());
     infowindow.open(map, marker);
     marker.setPosition(e.latLng);
     marker.setMap(map);
   }, 'json');
  };

  //google.maps.event.addListener(map, 'click', click_event);


   var map_change = function(){
       document.location.hash = '#' + map.getCenter().lat() + ',' + map.getCenter().lng() + ',' + map.getZoom();
   };
   google.maps.event.addListener(map, 'zoom_changed', map_change);
   google.maps.event.addListener(map, 'center_changed', map_change);
};

main.onload_user_iframe = function(){
    main.show_map();
};

main.onload_user_blob = function(){
  $('#btn-tab-list').click(function(e){
    $('.data-tab').hide();
    $('#data-tab-list').show();
  });
  $('#btn-tab-map').click(function(e){
    $('.data-tab').hide();
    $('#data-tab-map').show();
    if (!main.map_is_showed) {
      main.map_is_showed = true;
      main.show_map();
    }
    $('#data-tab-map').height(600);
  });

  if ($('#btn-tab-map').length) {
    $('#btn-tab-map').click();
  }

  main.register_import_button();

  if (!$('#blob-content').length) {
    return;
  }
  $.get('https://api.github.com/repos/' + encodeURIComponent(main.params.user) + '/' + encodeURIComponent(main.params.repository) + '/contents/' + main.params.path + '?ref=' + encodeURIComponent(main.params.branch), function(ret){
    if (ret.data.size > 0 && ret.data.content == '') {
      $('#blob-content').text("(Sorry about that, but we can't show files that are this big right now.)");
    } else if (ret.data.message == 'Not Found') {
      $('#blob-content').text("(File Not Found)");
    } else if (ret.meta.status == 403) {
      $('#blob-content').text("403: " + ret.data.message);
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

  if ($('body').is('.user_iframe')){
    main.onload_user_iframe();
  }

  if ($('body').is('.user_import')) {
    main.onload_user_import();
  }
};

$(main.onload);
