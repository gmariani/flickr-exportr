<?php
require_once("includes/phpFlickr.php");

$die_on_error = false;
$f = new phpFlickr(FLICKR_API_KEY, FLICKR_API_SECRET, $die_on_error);
$f->setToken(FLICKR_API_TOKEN);
$f->auth("read", false);
$f->enableCache("fs", "cache");

function cleanID($id)
{
    return preg_replace("/[^0-9,.]/", "", $id);
}

function toFileName($fileName)
{
    return trim(preg_replace("/[\:\<\>\"\/\\\|\?\*]/", "_", $fileName));
}

function toDirName($fileName)
{
    return rtrim(toFileName($fileName), ".");
}

function downloadFile($url, $path)
{
    $newfname = $path;
    $file = fopen($url, 'rb');
    // doesn't handle 502 properly
    if ($file) {
        $newf = fopen($newfname, 'wb');
        if ($newf) {
            while (!feof($file)) {
                fwrite($newf, fread($file, 1024 * 8), 1024 * 8);
            }
        }
        fclose($file);
        if ($newf) fclose($newf);
    }
}

// Get album list
if (isset($_REQUEST['getAlbums'])) {
    $result = $f->photosets_getList(null, null, null, 'url_s,views');
    if (false !== $f->error_code) {
        exit($f->error_code . ' : ' . $f->error_msg);
    }
    $albums = [];
    foreach ($result['photoset'] as $album) {
        $albums[] = ['name' => $album['title']['_content'], 'id' => $album['id']];
    }

    header('Content-type: application/json');
    echo json_encode($albums);
    exit;
}

// Get album photo list
if (isset($_REQUEST['getAlbumPhotos']) && isset($_REQUEST['albumID']) && isset($_REQUEST['albumName'])) {
    $photoset_id = cleanID($_REQUEST['albumID']);
    $extras = 'date_taken,original_format,geo,tags,o_dims,media, path_alias,url_o';
    $privacy_filter = '12345';
    $result = $f->photosets_getPhotos($photoset_id, $extras, $privacy_filter);
    $albumName = toDirName(urldecode($_REQUEST['albumName']));

    header('Content-type: application/json');
    if (false !== $f->error_code) {
        echo array('error_code' => $f->error_code, 'error_msg' => $f->error_msg);
        exit;
    }
    /*echo '<pre>';
	var_dump($result);
	echo '</pre>';*/
    $missing = [];
    $duplicates = [];
    $extra = [];
    $photos = $result['photoset']['photo'];
    $videoCount = 0;
    $photoCount = 0;
    foreach ($photos as $photo) {
        $title = $photo['title'];
        /*echo '<pre>';
		var_dump($photo);
		echo '</pre>';*/
        $photoCount += $photo['media'] == "photo" ? 1 : 0;
        $videoCount += $photo['media'] == "video" ? 1 : 0;
        $isVideo = ($photo['media'] == "video");
        $ext = $isVideo ? 'mp4' : $photo['originalformat'];
        $fileName3 = toFileName("{$title}_{$photo['id']}.{$ext}");
        $fileName = toFileName("{$title}_{$photo['id']}.{$photo['originalformat']}");
        $fileName2 = toFileName("{$title}_{$photo['id']}.mp4");
        $path = "export/${albumName}/{$fileName}";
        $path2 = "export/${albumName}/{$fileName2}";
        $path3 = "export/${albumName}/{$fileName3}";
        if (!file_exists($path3)) $missing[] = $path3;
        if (file_exists($path) && file_exists($path2)) {
            unlink($path);
            $duplicates[] = [$path, $path2];
        }
    }

    $localFiles = scandir("export/${albumName}");
    $localTotal = 0;
    foreach ($localFiles as $localFile) {
        if ($localFile == '.') continue;
        if ($localFile == '..') continue;
        $localTotal++;
        $match = false;
        foreach ($photos as $photo) {
            $isVideo = ($photo['media'] == "video");
            $ext = $isVideo ? 'mp4' : $photo['originalformat'];
            $fileName = toFileName("{$photo['title']}_{$photo['id']}.{$ext}");
            if ($localFile == $fileName) {
                $match = true;
                break;
            }
        }
        if (!$match) $extra[] = "export/${albumName}/${localFile}";
    }
    echo json_encode(['album' => $albumName, 'total' => count($photos), 'localTotal' => $localTotal, 'totalPhoto' => $photoCount, 'totalVideo' => $videoCount, 'missingPhotos' => $missing, 'duplicates' => $duplicates, 'extra' => $extra]);
    exit;
}

?>
<style>
    .messages {
        height: 300px;
        overflow: hidden;
        width: 100%;
    }

    .messages div {
        opacity: 1;
    }

    .messages .fade {
        transition: opacity 1s ease-in-out;
        -moz-transition: opacity 1s ease-in-out;
        -webkit-transition: opacity 1s ease-in-out;
        opacity: 0;
    }
</style>
<div id="exportr">
    <div id="flickrProgress"></div>
    <div id="albumProgress"></div>
    <div id="message" class="messages"></div>
</div>
<script>
    const elExportr = document.getElementById('exportr');
    const elFlickrProgress = document.getElementById('flickrProgress');
    const elAlbumProgress = document.getElementById('albumProgress');
    const elMessage = document.getElementById('message');
    let flickrTotal = 0;
    let albumTotal = 0;
    let albumCounter = 0;
    const startTime = new Date();
    let totalPhotos = 0;
    let localPhotos = 0;
    let missing = [];
    let duplicates = [];
    let extra = [];

    function updateFlickrProgress(count) {
        const curIndex = (flickrTotal - count);
        elFlickrProgress.innerText = `${((curIndex / flickrTotal) * 100).toFixed(2)}% (${curIndex} of ${flickrTotal} albums)`;
    }

    function updateAlbumProgress(count) {
        const curIndex = (albumTotal - count);
        elAlbumProgress.innerText = `${((curIndex / flickrTotal) * 100).toFixed(2)}% (${curIndex} of ${albumTotal} photos)`;
    }

    function addMessage(msg) {
        const firstMsg = elMessage.firstChild;
        const prevMsgs = elMessage.querySelectorAll('div:not(.fade)');
        const elMsg = document.createElement('div');
        elMsg.innerText = msg;

        for (let i = 0; i < prevMsgs.length; i++) {
            prevMsgs[i].classList.add("fade");
        }

        //elMessage.appendChild(elMsg);
        elMessage.insertBefore(elMsg, firstMsg);
    }

    function getExtension(fileName) {
        return fileName.split('.').pop();
    }

    function convertMS(ms) {
        let d, h, m, s;
        s = Math.floor(ms / 1000);
        m = Math.floor(s / 60);
        s = s % 60;
        h = Math.floor(m / 60);
        m = m % 60;
        d = Math.floor(h / 24);
        h = h % 24;
        h += d * 24;
        return h + ':' + m + ':' + s;
    }

    console.log(`Begin checking Flickr`);
    console.log(`Start Time: ${startTime.toLocaleString()}`);
    fetch('http://localhost/flickr/service.php?getAlbums')
        .then(response => response.json())
        .then(json => {
            flickrTotal = json.length;
            updateFlickrProgress(json.length);
            console.log(json);
            checkAlbum(json);
        })
        .catch(error => console.error(error));

    function checkAlbum(albums) {
        const album = albums[0];
        const albumName = album.name;
        albumCounter++;
        updateFlickrProgress(albums.length);
        addMessage(`Checking album "${album.name}"`);
        fetch(`http://localhost/flickr/service.php?getAlbumPhotos&albumID=${album.id}&albumName=${encodeURIComponent(albumName)}`)
            .then(response => response.json())
            .then(json => {
                console.log(json);
                if (json.total != json.localTotal) console.warn(`"${albumName}" / ${json.total} photos / ${json.localTotal} found`);
                totalPhotos += json.total;
                localPhotos += json.localTotal;
                missing = missing.concat(json.missingPhotos);
                duplicates = duplicates.concat(json.duplicates);
                extra = extra.concat(json.extra);
                if (albums.length > 1) {
                    checkAlbum(albums.slice(1));
                } else {
                    updateFlickrProgress(flickrTotal);
                    console.log(`Total Photos: ${totalPhotos}`);
                    console.log(`Total Local Photos: ${totalPhotos}`);
                    console.log(`All missing Photos`, missing);
                    console.log(`All duplicates`, duplicates);
                    console.log(`All extra`, extra);
                    addMessage(`Flickr check complete!`);
                    const endTime = new Date();
                    console.log(`End Time: ${endTime.toLocaleString()} - Total Time: ${convertMS(endTime-startTime)}`);
                }
            })
            .catch(error => console.error(error));
    }
</script>