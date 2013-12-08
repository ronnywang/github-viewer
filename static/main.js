var main = {};

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

    return ulw.lng() + "," + ulw.lat() + "," + lrw.lng() + "," + lrw.lat();
  };

  var polygonJSONToGMapPolygon = function(json){
    if (json.type != 'Polygon') {
      throw "Must be Polygon";
    }

    var paths = [];
    for (var i = 0; i < json.coordinates.length; i++) {
      var linestrings = json.coordinates[i];
      var path = [];
      if (linestrings[0] != linestrings[linestrings.length - 1]) {
        linestrings.push(linestrings[0]);
      }
      if (linestrings.length <= 3) {
        continue;
      }
      for (var j = 0; j < linestrings.length; j ++){
        var point = linestrings[j];
        path.push(new google.maps.LatLng(point[1], point[0]));
      }
      paths.push(path);
    }
    return new google.maps.Polygon({
      paths: paths,
      fillOpacity: 0,
      strokeWeight: 0
    });
  }

  var tile_set = [];
  var current_tile = 0;

  var getPolygonsFromJSON = function(json){
      if (json === null) { return []; }

      if (json.type == 'Polygon') {
          return [json.coordinates];
      } else if (json.type == 'MultiPolygon') {
          return json.coordinates;
      } else if (json.type == 'GeometryCollection') {
          var polygons = [];
          for (var i = 0; i < json.geometries.length; i ++) {
              polygons = polygons.concat(getPolygonsFromJSON(json.geometries[i]));
          }
          return polygons;
      } else {
          throw "Unknown type: " + json.type;
      }
  };

  var addTile = function(wms_set){
    for (var tab_id in wms_set) {
      addCustomControl(tab_id, tile_set.length);
      tile_set.push(tab_id);
    }
    CoordMapType = function(){};
    CoordMapType.prototype.tileSize = new google.maps.Size(tile_width, tile_height);
    CoordMapType.prototype.maxZoom = 17;
    CoordMapType.prototype.getTile = function(coord, zoom, ownerDocument) {
      var bbox = getBBoxFromTileZoom(coord, zoom);

      var div_dom = $('<div></div>');
      var img = new Image;
      img.style.width = this.tileSize.width + 'px';
      img.style.height = this.tileSize.height + 'px';
      img.className = 'wms-tile-img';
      img.src_set = {};
      for (var tab_id in wms_set) {
        img.src_set[tab_id] = wms_set[tab_id] + '&BBox=' + bbox + '&Width=' + tile_width + '&height=' + tile_height;
      }
      img.src = img.src_set[tile_set[current_tile]];

      if ($('#data-tab-map').attr('data-opacity')) {
        img.style.opacity = parseFloat($('#data-tab-map').attr('data-opacity'));
      }

      if ($('#data-tab-map').attr('data-clickzone-url')) {
        var clickzone_url = $('#data-tab-map').attr('data-clickzone-url');
        clickzone_url += '&BBox=' + bbox + '&Width=' + tile_width + '&height=' + tile_height;
        $.get(clickzone_url, function(ret){
          if (null === ret || 'object' != typeof(ret) || 'undefined' === typeof(ret.type)) {
            return;
          }
          var polygons = getPolygonsFromJSON(ret);
          gmap_polygons = polygons.map(function(poly){
            var gmap_polygon = polygonJSONToGMapPolygon({type: 'Polygon', coordinates: poly});
            gmap_polygon.setMap(map);
            google.maps.event.addListener(gmap_polygon, 'click', click_event);
            return gmap_polygon;
          });
          div_dom.data('gmap_polygons', gmap_polygons);
        }, 'json');
      }
      return div_dom.width(this.tileSize.width).height(this.tileSize.height).append(img)[0];
    };
    CoordMapType.prototype.releaseTile = function(node){
      if ('undefined' !== typeof($(node).data('gmap_polygons'))) {
        $(node).data('gmap_polygons').map(function(p){ p.setMap(null); delete(p); });
      }
    };
    CoordMapType.prototype.name = 'WMS';
    var coordinateMapType = new CoordMapType();
    map.overlayMapTypes.insertAt(0, coordinateMapType);
  }

  var map;

  var matches = document.location.hash.match('#([0-9.]*),([0-9.]*),([0-9]*)');
  var zoom = 8;
  var lat = 23.9720;
  var lng = 120.9777;

  var mapOptions = {
    streetViewControl: false,
    minZoom: 1
  };

  map = new google.maps.Map(document.getElementById('data-tab-map'), mapOptions);
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
  } else {
    myLatlng = new google.maps.LatLng(lat, lng);
    map.setCenter(myLatlng);
    map.setZoom(zoom);
  }

  var controlDiv = document.createElement('div');
  var controlUI_set = [];

  controlDiv.style.padding = '5px';
  map.controls[google.maps.ControlPosition.TOP_RIGHT].push(controlDiv);

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
      $('.wms-tile-img').each(function(){
        img = this;
        img.src = img.src_set[tile_set[current_tile]];
      });
    });
  };

  if ($('#data-tab-map').attr('data-wms-url')) {
    addTile({WMS: $('#data-tab-map').attr('data-wms-url')});
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
   $.get($('#data-tab-map').attr('data-click-url') + '&lat=' + e.latLng.lat() + '&lng=' + e.latLng.lng(), function(ret){
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

  $('#btn-import-csv').click(function(e){
    $(this).text($(this).attr('data-wording-importing'));

    $.post('/user/importcsv?user=' + encodeURIComponent(main.params.user) + '&repository=' + encodeURIComponent(main.params.repository) + '&path=' + encodeURIComponent(main.params.path) + '&branch=' + encodeURIComponent(main.params.branch), {}, function(ret){
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
  $.get('https://api.github.com/repos/' + encodeURIComponent(main.params.user) + '/' + encodeURIComponent(main.params.repository) + '/contents/' + main.params.path + '?ref=' + encodeURIComponent(main.params.branch), function(ret){
    if (ret.data.size > 0 && ret.data.content == '') {
      $('#blob-content').text("(Sorry about that, but we can't show files that are this big right now.)");
    } else if (ret.data.message == 'Not Found') {
      $('#blob-content').text("(File Not Found)");
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
};

$(main.onload);
