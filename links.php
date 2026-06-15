<?php

include("config.php");
include("functions.php");
include("session.php");
include("userclass.php");

$loggedin = $session->isLoggedIn();

if(!$loggedin) {
    header("Location: ".INDEXURL);
    exit;
}

$user = new User();
$user->login($session->user);

$perpage = 50;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $perpage;

$uid = $mysql->real_escape_string($user->id);

$total_res = $mysql->query("SELECT count(*) as `count` FROM `links` WHERE `userid` = '$uid'");
$total = $total_res->fetch_assoc()['count'];
$totalpages = max(1, ceil($total / $perpage));

$res = $mysql->query("SELECT `linkid`, `firstvisit`, `lastvisit`, `lastcommentcount` FROM `links` WHERE `userid` = '$uid' ORDER BY `lastvisit` DESC LIMIT $perpage OFFSET $offset");

htmlHeader("visited links - synccit", $loggedin);
?>

<div class="fourcol">
    <p><h2>visited links</h2></p>
    <p><?php echo $total; ?> total &mdash; page <?php echo $page; ?> of <?php echo $totalpages; ?></p>
</div>
<div class="twelvecol last">
    <table style="width:100%; border-collapse:collapse;">
        <tr>
            <th style="text-align:left; padding:4px 8px;">reddit id</th>
            <th style="text-align:left; padding:4px 8px;">first visited</th>
            <th style="text-align:left; padding:4px 8px;">last visited</th>
            <th style="text-align:left; padding:4px 8px;">comments</th>
        </tr>
        <?php while($row = $res->fetch_assoc()): ?>
        <tr>
            <td style="padding:4px 8px;"><a href="https://reddit.com/comments/<?php echo htmlspecialchars($row['linkid']); ?>" target="_blank"><?php echo htmlspecialchars($row['linkid']); ?></a></td>
            <td style="padding:4px 8px;"><?php echo date("Y-m-d", $row['firstvisit']); ?></td>
            <td style="padding:4px 8px;"><?php echo date("Y-m-d", $row['lastvisit']); ?></td>
            <td style="padding:4px 8px;"><?php echo (int)$row['lastcommentcount']; ?></td>
        </tr>
        <?php endwhile; ?>
    </table>

    <div style="margin-top:16px;">
        <?php if($page > 1): ?>
            <a href="<?php echo LINKSURL; ?>?page=<?php echo $page - 1; ?>">&laquo; prev</a>
            &nbsp;
        <?php endif; ?>
        <?php if($page < $totalpages): ?>
            <a href="<?php echo LINKSURL; ?>?page=<?php echo $page + 1; ?>">next &raquo;</a>
        <?php endif; ?>
    </div>
</div>

<?php htmlFooter(); ?>
