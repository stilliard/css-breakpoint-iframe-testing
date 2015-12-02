<?php

function getUrl()
{
    // default url
    $url = 'http://www.riverthoughtfulfishingbooks.co.uk/';
    // Posted valid URL?
    if (isset($_GET['url'])
        && is_string($_GET['url'])
        && filter_var(trim($_GET['url']), FILTER_VALIDATE_URL)) {
        $url = trim($_GET['url']);
    }
    return $url;
}
function request($url)
{
    return file_get_contents($url);
}
function htmlToDomDoc($html)
{
    $doc = new DOMDocument();
    @$doc->loadHTML($html);
    return $doc;
}
function getBaseUrls($url, $html)
{
    $parsedUrl = parse_url($url);
    $filename = basename($parsedUrl['path']);

    // return list of urls needed
    $urls = array(
        'root' => $parsedUrl['scheme'] . '://' . $parsedUrl['host'] . '/',
        'dir' => preg_replace('/'.preg_quote($filename).'$/', '', $url),
    );

    // look inside html for <base href="..."> tag
    $doc = htmlToDomDoc($html);
    $baseHref = $doc->getElementsByTagName('base');
    if ($baseHref && $baseHref[0]) {
        $baseHrefUrl = trim($baseHref[0]->getAttribute('href'));
        if ($baseHrefUrl != '') {
            $urls['dir'] = getBaseUrls($baseHrefUrl)['dir'];
        }
    }

    return $urls;
}
function fixBaseOfUrl($url, $baseUrls)
{
    $parsedUrl = parse_url($url);
    // does it already have https?:// etc. if so, return now
    if (isset($parsedUrl['scheme'])) {
        if (in_array($parsedUrl['scheme'], ['http', 'https'])) {
            return $url;
        } else {
            // invalid protocol given, e.g. file:// or ftp:// which we wont use
            return null;
        }
    }
    // does it start with "//"
    if (substr($url, 0, 2) == '//') {
        // fix to http:// and return
        return 'http:' . $url;
    }

    // does it start with "/" (absolute path)
    if (substr($url, 0, 1) == '/') {
        // prefix with base root url and return
        return $baseUrls['root'] . $url;
    }

    // else looks like a relative path
    // prefix with base directory url and return
    return $baseUrls['dir'] . $url;
}
function parseHtmlForCss($html, $baseUrls)
{
    // build up a string of all the css and css files content
    $css = '';

    // parse html
    $doc = htmlToDomDoc($html);

    // grab <style> tags
    $styles = $doc->getElementsByTagName('style');
    foreach ($styles as $style) {
        $css = $doc->saveHTML($style);
    }

    // grab <link> css tags
    $links = $doc->getElementsByTagName('link');
    foreach ($links as $link) {
        if (strtolower($link->getAttribute('rel')) == 'stylesheet') {
            // request css content for each css file found
            $linkUrl = fixBaseOfUrl($link->getAttribute('href'), $baseUrls);
            if ($linkUrl) {
                $css .= request($linkUrl);
            }
        }
    }

    // TODO now run over the css and look for @imports

    return $css;
}
function uniqueMultiArray($input)
{
    // @see http://stackoverflow.com/questions/307674/how-to-remove-duplicate-values-from-a-multi-dimensional-array-in-php#946300
    return array_map("unserialize", array_unique(array_map("serialize", $input)));
}
function findCssMediaQueryBreakpoints($css)
{
    $mqs = [];
    if (preg_match_all('/@media.*?(\(.*?\))\s*{/i', $css, $matches)) {
        foreach ($matches[0] as $mq) {
            // i'm currently only caring about width queries
            if (preg_match_all('/((min|max)(-device)?-width)\s*:\s*(([\d\.]+)(px|em))/', $mq, $match)) {
                foreach ($match[1] as $i => $key) {
                    $mqs[] = [$key, $match[4][$i]];
                }
            }
        }
    }
    // make sure we just have unique breakpoints
    $mqs = uniqueMultiArray($mqs);
    // and sort them by smallest first
    usort($mqs, function($a, $b) {
        $aSize = toPx($a[1]);
        $bSize = toPx($b[1]);
        if ($aSize == $bSize) return 0;
        return ($aSize < $bSize) ? -1 : 1;
    });
    return $mqs;
}
function toPx($size)
{
    if (substr($size, -2) == 'px') {
        return substr($size, 0, -2);
    }
    if (substr($size, -2) == 'em') {
        return substr($size, 0, -2) * 13; // 13 -> base px size
    }
    throw new Exception("Size not specified");
}
function variBreakpoint($breakpoint, $variAmount)
{
    $size = toPx($breakpoint[1]);
    if (substr($breakpoint[0], 0, 3) == 'min') { // if min width
        return $size + $variAmount;
    } else { // else max width
        return $size - $variAmount;
    }
}

// base px font-size
$basePxFontSize = '13';

// vari amount in px, e.g. to vari before or after the min/max widths for display
$variAmount = '10';

// get requested url and return it's html
$url = getUrl();
$html = request($url);
$breakpoints = [];
if ($html != '') {
    // parse html for css
    $css = parseHtmlForCss($html, getBaseUrls($url, $html));
    $breakpoints = findCssMediaQueryBreakpoints($css);
}

?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Responsive CSS Breakpoint Testing</title>
  <meta name="description" content="Test your site at different responsive css breakpoints / media queries">

  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/jqueryui/1.11.4/jquery-ui.min.css">
  <style>
    body {
        font: <?php echo $basePxFontSize; ?>px/1.36 sans-serif;
    }
    html, body { height: 100%; }
    .iframe-blocks-container {
        width: 100%;
        overflow: auto;
    }
    .iframe-blocks-container-inner {
        width: 10000px;
    }
    .iframe-blocks-container,
    .iframe-blocks-container-inner,
    .iframe-block,
    .iframe-block iframe {
        height: calc(100% - 40px);
    }
    .iframe-block {
        margin-right: 1em;
        float: left;
    }
    .iframe-block iframe {
        width: 100%;
    }

    .url-form {
        background: #CCC;
        padding: 1em;
    }
    .url-form label,
    .url-form input,
    .url-form button {
        display: block;
        float: left;
    }
    .url-form label {
        width: 10%;
    }
    .url-form input {
        width: 70%;
    }
    .url-form button {
        width: 20%;
    }

    /*
    Better box sizing by default on all elements
    http://css-tricks.com/international-box-sizing-awareness-day/
    */
    *, *:before, *:after {
        /* Chrome 9-, Safari 5-, iOS 4.2-, Android 3-, Blackberry 7- */
        -webkit-box-sizing: border-box;

        /* Firefox (desktop or Android) 28- */
        -moz-box-sizing: border-box;

        /* Firefox 29+, IE 8+, Chrome 10+, Safari 5.1+, Opera 9.5+, iOS 5+, Opera Mini Anything, Blackberry 10+, Android 4+ */
        box-sizing: border-box;
    }


    /* clearfix */
    .clearfix:before,
    .clearfix:after {
    content: " ";
    display: table;
    }
    .clearfix:after {
    clear: both;
    }
    .clearfix {
    *zoom: 1; /* For IE 6/7 (trigger hasLayout) */
    }
  </style>

</head>
<body>

    <form class="url-form clearfix" method="get">
        <label for="url">URL:</label>
        <input type="url" name="url" id="url" value="<?php echo $url; ?>">
        <button type="submit">Run</button>
    </form>

    <?php if (empty($breakpoints)) {
        echo '<p><strong>Breakpoints not found for this site, either this site has none or it restricts this tool from running. Please try a similar tool such as https://chrome.google.com/webstore/detail/emmet-review/epejoicbhllgiimigokgjdoijnpaphdp?hl=en</strong></p>';
    } ?>

    <div class="iframe-blocks-container">
        <div class="iframe-blocks-container-inner">
            <?php foreach ($breakpoints as $breakpoint) { ?>
            <div class="iframe-block" style="width:<?php echo variBreakpoint($breakpoint, $variAmount); ?>px">
                <div class="iframe-block-title"><?php echo $breakpoint[0] . ': ' . $breakpoint[1]; ?></div>
                <iframe src="<?php echo $url; ?>"></iframe>
            </div>
            <?php } ?>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/1.11.3/jquery.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jqueryui/1.11.4/jquery-ui.min.js"></script>
    <script>
        (function ($) {
            $('.iframe-block').resizable();
        }(jQuery));
    </script>

    </script>

</body>
</html>
