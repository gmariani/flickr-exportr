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

// https://stackoverflow.com/questions/2602612/php-remote-file-size-without-downloading-file
/**
 * Returns the size of a file without downloading it, or -1 if the file
 * size could not be determined.
 *
 * @param $url - The location of the remote file to download. Cannot
 * be null or empty.
 *
 * @return The size of the file referenced by $url, or -1 if the size
 * could not be determined.
 */
function checkFileSize($url)
{
    // Assume failure.
    $result = -1;

    $curl = curl_init($url);

    // Issue a HEAD request and follow any redirects.
    curl_setopt($curl, CURLOPT_NOBODY, true);
    curl_setopt($curl, CURLOPT_HEADER, true);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
    //curl_setopt( $curl, CURLOPT_USERAGENT, get_user_agent_string() );

    $data = curl_exec($curl);
    curl_close($curl);

    if ($data) {
        $content_length = "unknown";
        $status = "unknown";

        if (preg_match("/^HTTP\/1\.[01] (\d\d\d)/", $data, $matches)) {
            $status = (int)$matches[1];
        }

        if (preg_match("/Content-Length: (\d+)/", $data, $matches)) {
            $content_length = (int)$matches[1];
        }

        // http://en.wikipedia.org/wiki/List_of_HTTP_status_codes
        if ($status == 200 || ($status > 300 && $status <= 308)) {
            $result = $content_length;
        }
    }

    return $result;
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

function find_duplicates($album, $photo1)
{
    $count = 0;
    $title = $photo1['originaltitle'];
    $id = $photo1['id'];
    foreach ($album as $photo) {
        $title2 = isset($photo['originaltitle']) ? $photo['originaltitle'] : $photo['title'];
        $id2 = $photo['id'];
        if ($title2 == $title && $id2 != $id) {
            $count++;
        }
    }
    return $count;
}

// Get album photo list
if (isset($_REQUEST['getAlbumPhotos']) && isset($_REQUEST['albumID']) && isset($_REQUEST['albumName'])) {
    $photoset_id = cleanID($_REQUEST['albumID']);
    $extras = 'date_taken,original_format,geo,tags,o_dims,media, path_alias,url_o';
    $privacy_filter = '12345';
    $result = $f->photosets_getPhotos($photoset_id, $extras, $privacy_filter);
    $albumName = toDirName($_REQUEST['albumName']);

    // Check all photos to see if we need to download them
    foreach ($result['photoset']['photo'] as &$photo) {
        $url = $photo['url_o'];
        $photo['originaltitle'] = $photo['title'];

        // Check album if more than one photo exists with same title, if so add ID
        /*if (find_duplicates($result['photoset']['photo'], $photo) > 1) {
			$photo['title'] = $photo['title'] . '_' . $photo['id'];
		}*/
        $ext = $photo['media'] == 'video' ? 'mp4' : $photo['originalformat'];
        $fileName = "{$photo['title']}_{$photo['id']}.{$ext}";
        $path = "export/${albumName}/" . toFileName($fileName);
        $photo['remotesize'] = 0; //checkFileSize($url);
        $photo['localPath'] = $path;
        if (file_exists($path)) {
            $photo['isdownloaded'] = 1;
            $photo['localsize'] = filesize($path);
            // Skip checking each file for now
            $photo['remotesize'] = $photo['localsize'];
        }
    }

    // Return result
    header('Content-type: application/json');
    $json = (false !== $f->error_code) ? array('error_code' => $f->error_code, 'error_msg' => $f->error_msg) : $result;
    echo json_encode($json);
    exit;
}

// Get individual photo
if (isset($_REQUEST['getPhoto']) && isset($_REQUEST['fileURL']) && isset($_REQUEST['fileName']) && isset($_REQUEST['albumName'])) {
    $url = urldecode($_REQUEST['fileURL']);
    $albumName = toDirName(urldecode($_REQUEST['albumName']));
    $path = "export/${albumName}/" . toFileName(urldecode($_REQUEST['fileName']));
    if (!file_exists('export')) mkdir('export', 0777, true);
    if (!file_exists("export/{$albumName}")) mkdir("export/{$albumName}", 0777, true);

    header('Content-type: application/json');
    if (!file_exists($path)) {
        $opts = array(
            'http' => array(
                'method' => 'GET',
                'header' => "Accept-language: en\r\n" .
                    "Cookie: DATA_HERE\r\n",
                'max_redirects' => '20'
            )
        );
        $context = stream_context_create($opts);
        file_put_contents($path, fopen($url, 'r', false, $context));
        //downloadFile($url, $path);
        $json = (false !== $f->error_code) ? array('status' => 'error', 'error_code' => $f->error_code, 'error_msg' => $f->error_msg) : array('status' => 'success', 'message' => 'Saved file ' . $path);
        echo json_encode($json);
    } else {
        echo json_encode(array('status' => 'success', 'message' => 'Skip existing file ' . $path));
    }
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

    console.log(`Begin downloading Flickr`);
    console.log(`Start Time: ${startTime.toLocaleString()}`);
    fetch('download_flickr.php?getAlbums')
        .then(response => response.json())
        .then(json => {
            flickrTotal = json.length;
            updateFlickrProgress(json.length);
            console.log(json);
            downloadAlbum(json);
        })
        .catch(error => console.error(error));

    function downloadAlbum(albums) {
        const album = albums[0];
        const albumName = album.name;
        albumCounter++;
        updateFlickrProgress(albums.length);
        addMessage(`Downloading album "${album.name}"`);
        fetch(`download_flickr.php?getAlbumPhotos&albumID=${album.id}&albumName=${albumName}`)
            .then(response => response.json())
            .then(json => {
                //console.log(json);
                const photoset = json.photoset.photo;
                albumTotal = photoset.length;
                updateAlbumProgress(photoset.length);
                downloadPhoto(photoset, album.name, albums);
            })
            .catch(error => console.error(error));
    }

    function downloadPhoto(photos, albumName, albums) {
        const photo = photos[0];
        const isVideo = (photo.media == 'video');
        const currentformat = getExtension(photo.url_o);
        //const fileName = (photo.originalformat != currentformat) ? `${photo.title}.${currentformat}` : `${photo.title}.${photo.originalformat}`;
        const fileName = isVideo ? `${photo.title}_${photo.id}.mp4` : `${photo.title}_${photo.id}.${photo.originalformat}`;
        const fileURL = isVideo ? `https://www.flickr.com/video_download.gne?id=${photo.id}` : photo.url_o;
        let resp;
        updateAlbumProgress(photos.length);
        let isCorrupt = (photo.isdownloaded == 1 && photo.localsize != photo.remotesize);

        if (photo.title.length > 200) {
            addMessage(`Corrupt file name "${albumName}/${fileName}", skipping`);
            console.warn(`Corrupt file name "${albumName}/${fileName}", skipping`);
            onDownloadPhoto({}, photos, albumName, albums);
            return;
        }

        if (isCorrupt) {
            addMessage(`Photo "${albumName}/${fileName}" is corrupt!`);
            console.warn(`Photo "${albumName}/${fileName}" is corrupt!`, photo.localsize, photo.remotesize);
        }

        if (isVideo) {
            console.info(`Video "${albumName}/${fileName}" !`, photo);
        }

        if (photo.isdownloaded == 1 && !isCorrupt) {
            addMessage(`Already downloaded photo "${albumName}/${fileName}"`);
            onDownloadPhoto({}, photos, albumName, albums);
        } else {
            addMessage(`Downloading photo "${albumName}/${fileName}"`);
            console.log(`Downloading photo "${albumName}/${fileName}"`);
            fetch(`download_flickr.php?getPhoto&fileURL=${encodeURIComponent(fileURL)}&fileName=${encodeURIComponent(fileName)}&albumName=${encodeURIComponent(albumName)}`)
                .then(response => {
                    resp = response;
                    return response.json()
                })
                .then(json => {
                    onDownloadPhoto(json, photos, albumName, albums);
                })
                .catch(error => {
                    console.error(`Error downloading "${albumName}/${fileName}", skip`, error);
                    console.error(resp);
                    //console.error(resp.text());
                    onDownloadPhoto({
                        error: error
                    }, photos, albumName, albums)
                });
        }

    }

    function onDownloadPhoto(json, photos, albumName, albums) {
        //addMessage(`Photo download complete!`);
        //console.log(json);
        //console.log(photos);
        // Skip error photos
        if (photos.length > 1 || json.error) {
            // Start next photo download
            downloadPhoto(photos.slice(1), albumName, albums);
        } else {
            // Start next album download
            addMessage(`Album download complete!`);
            updateAlbumProgress(albumTotal);
            if (albums.length > 1) {
                //if (albumCounter < 3) {
                downloadAlbum(albums.slice(1));
                //} else {
                //	addMessage(`Flickr download fake complete!`);
                //}
            } else {
                updateFlickrProgress(flickrTotal);
                addMessage(`Flickr download complete!`);
                const endTime = new Date();
                console.log(`End Time: ${endTime.toLocaleString()} - Total Time: ${convertMS(endTime-startTime)}`);
            }
        }
    }
</script>