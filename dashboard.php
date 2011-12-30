<?php

include_once( 'common.php' );

$_DB = new mysqli( $_MYSQL_HOST, $_MYSQL_USER, $_MYSQL_PASS, $_MYSQL_DB );
if (mysqli_connect_errno()) {
    fail( 'Error connecting to db: ' . mysqli_connect_error() );
}

//
// handle note updates
//

$stmt = $_DB->prepare( 'INSERT INTO metadata (bug, note) VALUES (?, ?) ON DUPLICATE KEY UPDATE note=VALUES(note)' );
if ($_DB->errno) {
    fail( 'Error preparing statement: ' . $_DB->error );
}
foreach ($_POST AS $key => $value) {
    if (strncmp( $key, 'note', 4 ) == 0) {
        $stmt->bind_param( 'is', intval( substr( $key, 4 ) ), trim( $value ) );
        $stmt->execute();
    }
}

//
// read metadata
//

$meta_titles = array();
$meta_notes = array();

$result = $_DB->query( "SELECT * FROM metadata" );
if (! $result) {
    fail( "Unable to load metadata" );
}
while ($row = $result->fetch_assoc()) {
    if (strlen( $row['title'] ) > 0) {
        $meta_titles[ $row['bug'] ] = $row['title'];
    }
    if (strlen( $row['note'] ) > 0) {
        $meta_notes[ $row['bug'] ] = $row['note'];
    }
}

//
// main helpers and rendering code
//

function loadTable( $table ) {
    global $_DB;
    $result = $_DB->query( "SELECT * FROM $table WHERE viewed=0 ORDER BY stamp, id ASC" );
    if (! $result) {
        fail( "Unable to load $table" );
    }
    return $result;
}

function escapeHTML( $stuff ) {
    $stuff = str_replace( '&', '&amp;', $stuff );
    $stuff = str_replace( array( '<', '>', '"' ), array( '&lt;', '&gt;', '&quot;' ), $stuff );
    return $stuff;
}

function stripWhitespace( $stuff ) {
    return preg_replace( '/\s/', '', $stuff );
}

function column( &$reasons ) {
    if (array_search( 'review', $reasons ) !== FALSE) {
        return 0;
    } else if (array_search( 'request', $reasons ) !== FALSE) {
        return 0;
    } else if (array_search( 'AssignedTo', $reasons ) !== FALSE) {
        return 1;
    } else if (array_search( 'Reporter', $reasons ) !== FALSE) {
        return 1;
    } else if (array_search( 'CC', $reasons ) !== FALSE) {
        return 2;
    } else if (array_search( 'Watch', $reasons ) !== FALSE) {
        return 3;
    } else {
        return 3;
    }
}

$filterComments = array();
$filterFlags = array();
$numRows = 0;
$bblocks = array();
$columns = array();

$result = loadTable( 'reviews' );
while ($row = $result->fetch_assoc()) {
    $numRows++;
    $stamp = strtotime( $row['stamp'] );
    $bblocks[ $row['bug'] ][ $stamp ] .= sprintf( '<div class="row" id="r%d">%s: %s%s <a href="%s/page.cgi?id=splinter.html&bug=%d&attachment=%d">%s</a>%s</div>',
                                                $row['id'],
                                                escapeHTML( $row['author'] ),
                                                ($row['feedback'] ? 'f' : 'r'),
                                                ($row['granted'] ? '+' : '-'),
                                                $_BASE_URL,
                                                $row['bug'],
                                                $row['attachment'],
                                                escapeHTML( $row['title'] ),
                                                (strlen( $row['comment'] ) > 0 ? ' with comments: ' . escapeHTML( $row['comment'] ) : '') ) . "\n";
    $reasons[ $row['bug'] ][] = 'review';

    $filterComments[ $row['attachment'] ][] = $row['comment'];
    $type = ($row['feedback'] ? 'feedback' : 'review');
    $filterFlags[ $row['attachment'] ][] = array( "{$type}?({$row['authoremail']})", "{$type}" . ($row['granted'] ? '+' : '-') );
}

$result = loadTable( 'requests' );
while ($row = $result->fetch_assoc()) {
    $numRows++;
    $stamp = strtotime( $row['stamp'] );
    $bblocks[ $row['bug'] ][ $stamp ] .= sprintf( '<div class="row" id="q%d">%sr? <a href="%s/page.cgi?id=splinter.html&bug=%d&attachment=%d">%s</a>%s</div>',
                                                $row['id'],
                                                ($row['cancelled'] ? '<strike>' : ''),
                                                $_BASE_URL,
                                                $row['bug'],
                                                $row['attachment'],
                                                escapeHTML( $row['title'] ),
                                                ($row['cancelled'] ? '</strike>' : '') ) . "\n";
    $reasons[ $row['bug'] ][] = 'request';

    $type = ($row['feedback'] ? 'feedback' : 'review');
    $filterFlags[ $row['attachment'] ][] = array( '', "{$type}?({$_ME})" );
}

$result = loadTable( 'newbugs' );
while ($row = $result->fetch_assoc()) {
    $numRows++;
    $stamp = strtotime( $row['stamp'] );
    $bblocks[ $row['bug'] ][ $stamp ] .= sprintf( '<div class="row" id="n%d">New: <a href="%s/show_bug.cgi?id=%d">%s</a></div><div class="desc">%s</div>',
                                                $row['id'],
                                                $_BASE_URL,
                                                $row['bug'],
                                                escapeHTML( $row['title'] ),
                                                escapeHTML( $row['description'] ) ) . "\n";
    $reasons[ $row['bug'] ][] = $row['reason'];
}

$result = loadTable( 'changes' );
while ($row = $result->fetch_assoc()) {
    $hide = false;
    // hide duplicated review flag changes (one from Type=request email and one from Type=changed email)
    if (strpos( $row['field'], 'Flags' ) !== FALSE) {
        $matches = array();
        if (preg_match( "/^Attachment #(\d+) Flags/", $row['field'], $matches ) > 0) {
            if (isset( $filterFlags[ $matches[1] ] )) {
                foreach ($filterFlags[ $matches[1] ] AS $filterFlag) {
                    if (stripWhitespace( $row['oldval'] ) == $filterFlag[0] && stripWhitespace( $row['newval'] ) == $filterFlag[1]) {
                        $hide = true;
                        break;
                    }
                }
            }
        }
    }

    $numRows++;
    $stamp = strtotime( $row['stamp'] );
    $bblocks[ $row['bug'] ][ $stamp ] .= sprintf( '<div class="row"%s id="d%d">%s: %s &rarr; %s</div>',
                                                ($hide ? ' style="display: none"' : ''),
                                                $row['id'],
                                                escapeHTML( $row['field'] ),
                                                escapeHTML( $row['oldval'] ),
                                                escapeHTML( $row['newval'] ) ) . "\n";
    $reasons[ $row['bug'] ][] = $row['reason'];
}

$result = loadTable( 'comments' );
while ($row = $result->fetch_assoc()) {
    $hide = false;
    // Hide duplicated review comments (one from Type=request email and one from Type=changed email)
    if (strpos( $row['comment'], "Review of attachment" ) !== FALSE) {
        $matches = array();
        if (preg_match( "/^Comment on attachment (\d+)\n  -->.*\n.*\n\nReview of attachment \d+:\n -->.*\n--*-\n\n/", $row['comment'], $matches ) > 0) {
            foreach ($filterComments[ $matches[1] ] AS $filterComment) {
                if (strpos( $row['comment'], $filterComment ) !== FALSE) {
                    $hide = true;
                    break;
                }
            }
        }
    }

    $numRows++;
    $stamp = strtotime( $row['stamp'] );
    $bblocks[ $row['bug'] ][ $stamp ] .= sprintf( '<div class="row"%s id="c%d">%s <a href="%s/show_bug.cgi?id=%d#c%d">said</a>:<br/>%s</div>',
                                                ($hide ? ' style="display: none"' : ''),
                                                $row['id'],
                                                escapeHTML( $row['author'] ),
                                                $_BASE_URL,
                                                $row['bug'],
                                                $row['commentnum'],
                                                escapeHTML( $row['comment'] ) ) . "\n";
    $reasons[ $row['bug'] ][] = $row['reason'];
}

foreach ($bblocks AS $bug => &$block) {
    ksort( $block, SORT_NUMERIC );
    $touchTime = key( $block );
    $block = sprintf( '<div class="bug" id="bug%d"><div class="title"><a href="%s/show_bug.cgi?id=%d">Bug %d</a> %s <a class="wipe" href="#">X</a></div>%s</div>',
                      $bug,
                      $_BASE_URL,
                      $bug,
                      $bug,
                      escapeHTML( $meta_titles[ $bug ] ),
                      implode( "\n", $block ) ) . "\n";
    $columns[ column( $reasons[ $bug ] ) ][ $touchTime ] .= $block;
}
$_DB->close();

$errors = 0;
$files = scandir( $BUGMASH_DIR );
foreach ($files AS $file) {
    if (strpos( strrev( $file ), "rre." ) === 0) {
        $errors++;
    }
}

// render
header( 'Content-Type: text/html; charset=utf8' );
header( 'Strict-Transport-Security: max-age=31536000; includeSubDomains' );
?>
<!DOCTYPE html>
<html>
 <head>
  <title>Bugmash Dashboard (<?php echo $numRows, ' unviewed, ', $errors, ' errors'; ?>)</title>
  <base target="_blank"/>
  <style type="text/css">
body {
    font-family: sans-serif;
    font-size: 10pt;
}
.column {
    width: 25%;
    float: left;
}
.bug {
    margin: 2px;
    padding: 2px;
    border: 1px solid;
}
.row {
    border-bottom: dashed 1px;
    word-wrap: break-word;  /* deprecated by css3-text, but the one that firefox picks up */
    overflow-wrap: break-word hyphenate; /* what i really want as per css3-text */
}
.row:last-child {
    border-bottom: none;
}
div.title {
    background-color: lightblue;
    margin-bottom: 2px;
}
a.wipe {
    float: right;
}
.noteinput {
    width: 80%;
}
  </style>
  <script type="text/javascript">
    function wipe(e) {
        var block = e.target;
        while (block.className != "bug") {
            block = block.parentNode;
        }
        var items = block.querySelectorAll( "div.row" );
        var ids = new Array();
        for (var i = 0; i < items.length; i++) {
            ids.push( items[i].id );
        }
        block.style.display = 'none';
        var xhr = new XMLHttpRequest();
        xhr.onreadystatechange = function() {
            if (xhr.readyState != 4) {
                return;
            }
            if (xhr.status == 200) {
                block.parentNode.removeChild(block);
                document.title = document.title.replace( /\d+ unviewed/, function(unviewed) { return (unviewed.split(" ")[0] - ids.length) + " unviewed"; } );
            } else {
                block.style.display = 'block';
                e.target.textContent = "[E]";
            }
        };
        var body = "ids=" + ids.join( "," );
        xhr.open( "POST", "wipe.php", true );
        xhr.setRequestHeader( "Content-Type", "application/x-www-form-urlencoded" );
        xhr.setRequestHeader( "Content-Length", body.length );
        xhr.send( body );
        e.preventDefault();
    }

    document.addEventListener( "DOMContentLoaded", function() {
        var wipers = document.querySelectorAll( "a.wipe" );
        for (var i = 0; i < wipers.length; i++) {
            wipers[i].addEventListener( "click", wipe, true );
        }
    }, true );

    function addNote() {
        var notediv = document.createElement( "div" );
        notediv.className = "newnote";
        var sibling = document.getElementById( "notebuttons" );
        sibling.parentNode.insertBefore( notediv, sibling );
        notediv.innerHTML = '<span>Bug <input type="text" size="7" maxlength="10"/></span>: <input class="noteinput" type="text"/><br/>';
    }

    function setNoteNames() {
        var newnotes = document.getElementsByClassName( "newnote" );
        while (newnotes.length > 0) {
            var newnote = newnotes[0];
            var bugnumbertext = newnote.getElementsByTagName( "input" )[0].value;
            var bugnumber = parseInt( bugnumbertext );
            if (isNaN( bugnumber )) {
                if (window.confirm( "Unable to parse " + bugnumbertext + " as a bug number; replace with 0 and continue anyway?" )) {
                    bugnumber = 0;
                } else {
                    return false;
                }
            }
            var anchor = document.createElement( "a" );
            anchor.setAttribute( "href", "<?php echo $_BASE_URL; ?>/show_bug.cgi?id=" + bugnumber );
            anchor.textContent = "Bug " + bugnumber;
            newnote.replaceChild( anchor, newnote.getElementsByTagName( "span" )[0] );
            newnote.getElementsByTagName( "input" )[0].setAttribute( "name", "note" + bugnumber );
            newnote.className = "note";
        }
        return true;
    }
  </script>
 </head>
 <body>
<?php
echo '  <form onsubmit="return setNoteNames()" method="POST" target="_self">', "\n";
echo '   <fieldset>', "\n";
echo '    <legend>Bug notes</legend>', "\n";
foreach ($meta_notes AS $bug => $note) {
    echo sprintf( '    <div class="note"><a href="%s/show_bug.cgi?id=%d">Bug %d</a>: <input class="noteinput" type="text" name="note%d" value="%s"/><br/>%s</div>',
                  $_BASE_URL,
                  $bug,
                  $bug,
                  $bug,
                  escapeHTML( $note ),
                  escapeHTML( $meta_titles[ $bug ] ) ),
        "\n";
}
echo '    <div id="notebuttons">', "\n";
echo '     <input type="button" value="Add note" onclick="addNote()"/>', "\n";
echo '     <input type="submit" id="savenotes" value="Save notes"/>', "\n";
echo '    </div>', "\n";
echo '   </fieldset>', "\n";
echo '  </form>', "\n";

for ($i = 0; $i < 4; $i++) {
    $buglist = $columns[$i];
    echo '  <div class="column">', "\n";
    if (count( $buglist ) > 0) {
        ksort( $buglist, SORT_NUMERIC );
        foreach ($buglist AS $time => &$block) {
            echo $block, "\n";
        }
    }
    echo '   &nbsp;';   // so that after wiping all the blocks there is still space reserved
    echo '  </div>', "\n";
}
?>
 </body>
</html>
