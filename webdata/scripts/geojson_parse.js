var fs = require('fs');



// node geojson_parse.js [get_type] [file]
// node geojson_parse.js [split_feature] [file] [target]
fs.readFile(process.argv[3], function(err, data){
    if ('get_type' == process.argv[2]) {
        if (data.length < 10000000) {
            var json = JSON.parse(data);
            console.log(json.type);
        } else {
            var start = data.toString('utf-8', 0, 4096).search('"type":"') + '"type":"'.length;
            var tmp = data.toString('utf-8', start, start + 30);
            console.log(tmp.substr(0, tmp.search('"')));
        }
    } else if ('get_content' == process.argv[2]) {
        var base64 = require('base64-stream');
        var decoder = base64.decode();
        var stream = require('stream');
        var input = new stream.PassThrough();
        var output = new stream.PassThrough();

        input.pipe(decoder).pipe(output);

        output.on('data', function (data) {
            process.stdout.write(data);
        });
        var start = data.toString('utf-8', 0, 4096).search('"content":"') + '"content":"'.length;
        var chunk_size = 62 * 100;
        while (true) {
            var tmp = data.toString('utf-8', start, start + chunk_size).replace(/\\n/g, "\n");
            if (tmp.search('"') >= 0) {
                input.write(tmp.substr(0, tmp.search('"')));
                break;
            } else {
                input.write(tmp);
            }
            start += chunk_size;
        }

    } else if ('split_feature' == process.argv[2]) {
        var json = JSON.parse(data);
        for (var i = 0; i < json.features.length; i ++) {
            fs.writeFileSync(process.argv[4] + '/' + i + '.json', JSON.stringify(json.features[i]));
        }
    }
});
