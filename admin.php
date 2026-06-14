<?php

include("config.php");
include("functions.php");
include("session.php");
include("userclass.php");

if(!isset($_SESSION['temphash'])) {
    $_SESSION['temphash'] = hash("sha256", genrand());
}

$loggedin = $session->isLoggedIn();

if(!$loggedin) {
    header("Location: ".LOGINURL);
    exit;
}

$currentUser = new User();
$currentUser->login($session->user);

$adminCheck = $mysql->query("SELECT `is_admin` FROM `user` WHERE `id` = '".$mysql->real_escape_string($currentUser->id)."' LIMIT 1");
$adminRow = $adminCheck->fetch_assoc();
if(!$adminRow || !$adminRow['is_admin']) {
    header("Location: ".INDEXURL);
    exit;
}

$hash = $_SESSION['temphash'];
$action = isset($_GET['action']) ? $_GET['action'] : 'list';
$target_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$message = "";
$error = "";

if($_SERVER['REQUEST_METHOD'] === 'POST') {
    $post_action = isset($_POST['action']) ? $_POST['action'] : '';
    $post_id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

    if((isset($_POST['csrf']) ? $_POST['csrf'] : '') !== $hash) {
        $error = "invalid request, retry";
        $post_action = '';
    }

    if($post_action === 'create') {
        $uname = trim(isset($_POST['username']) ? $_POST['username'] : '');
        $email = trim(isset($_POST['email']) ? $_POST['email'] : '');
        $password = isset($_POST['password']) ? $_POST['password'] : '';
        $is_admin_new = isset($_POST['is_admin']) ? 1 : 0;

        if(strlen($uname) < 3) {
            $error = "username must be at least 3 characters";
            $action = 'create';
        } elseif(!preg_match("/^[a-zA-Z0-9_]+$/", $uname)) {
            $error = "username must be letters, numbers, or underscores";
            $action = 'create';
        } elseif(strlen($password) < 6) {
            $error = "password must be at least 6 characters";
            $action = 'create';
        } else {
            $hashset = create_hash($password);
            $pieces = explode(":", $hashset);
            $salt = $pieces[2];
            $phash = $pieces[3];
            $sql = "INSERT INTO `user` (
                `id`, `username`, `passhash`, `salt`, `email`, `created`, `lastip`, `is_admin`
            ) VALUES (
                NULL,
                '".$mysql->real_escape_string($uname)."',
                '".$mysql->real_escape_string($phash)."',
                '".$mysql->real_escape_string($salt)."',
                '".$mysql->real_escape_string($email)."',
                '".time()."',
                '".$mysql->real_escape_string($_SERVER['REMOTE_ADDR'])."',
                '".$is_admin_new."'
            )";
            if($mysql->query($sql)) {
                $message = "user created";
                $action = 'list';
            } else {
                $error = ($mysql->errno == 1062) ? "username already exists" : "database error";
                $action = 'create';
            }
        }

    } elseif($post_action === 'edit') {
        $uname = trim(isset($_POST['username']) ? $_POST['username'] : '');
        $email = trim(isset($_POST['email']) ? $_POST['email'] : '');
        $is_admin_new = isset($_POST['is_admin']) ? 1 : 0;
        $action = 'edit';
        $target_id = $post_id;

        if($post_id <= 0) {
            $error = "invalid user";
        } elseif($post_id == $currentUser->id && !$is_admin_new) {
            $error = "cannot remove your own admin status";
        } elseif(strlen($uname) < 3) {
            $error = "username must be at least 3 characters";
        } elseif(!preg_match("/^[a-zA-Z0-9_]+$/", $uname)) {
            $error = "username must be letters, numbers, or underscores";
        } else {
            $sql = "UPDATE `user` SET
                `username` = '".$mysql->real_escape_string($uname)."',
                `email`    = '".$mysql->real_escape_string($email)."',
                `is_admin` = '".$is_admin_new."'
                WHERE `id` = '".$post_id."' LIMIT 1";
            if($mysql->query($sql)) {
                $message = "user updated";
            } else {
                $error = ($mysql->errno == 1062) ? "username already exists" : "database error";
            }
        }

    } elseif($post_action === 'password') {
        $password = isset($_POST['password']) ? $_POST['password'] : '';
        $action = 'edit';
        $target_id = $post_id;

        if($post_id <= 0) {
            $error = "invalid user";
        } elseif(strlen($password) < 6) {
            $error = "password must be at least 6 characters";
        } else {
            $hashset = create_hash($password);
            $pieces = explode(":", $hashset);
            $salt = $pieces[2];
            $phash = $pieces[3];
            $sql = "UPDATE `user` SET
                `passhash` = '".$mysql->real_escape_string($phash)."',
                `salt`     = '".$mysql->real_escape_string($salt)."',
                `canreset` = '0'
                WHERE `id` = '".$post_id."' LIMIT 1";
            if($mysql->query($sql)) {
                $message = "password updated";
            } else {
                $error = "database error";
            }
        }

    } elseif($post_action === 'delete') {
        if($post_id <= 0) {
            $error = "invalid user";
        } elseif($post_id == $currentUser->id) {
            $error = "cannot delete yourself";
        } else {
            $mysql->query("DELETE FROM `authcodes`  WHERE `userid` = '".$post_id."'");
            $mysql->query("DELETE FROM `logincodes` WHERE `userid` = '".$post_id."'");
            $mysql->query("DELETE FROM `links`      WHERE `userid` = '".$post_id."'");
            if($mysql->query("DELETE FROM `user` WHERE `id` = '".$post_id."' LIMIT 1")) {
                $message = "user deleted";
            } else {
                $error = "database error";
            }
        }
        $action = 'list';

    } elseif($post_action === 'add_key') {
        $desc = trim(isset($_POST['description']) ? $_POST['description'] : '');
        $action = 'edit';
        $target_id = $post_id;

        if($post_id <= 0) {
            $error = "invalid user";
        } elseif(empty($desc)) {
            $error = "device name required";
        } else {
            $urow = $mysql->query("SELECT `username` FROM `user` WHERE `id` = '".$post_id."' LIMIT 1")->fetch_assoc();
            if(!$urow) {
                $error = "user not found";
            } else {
                $key = genrand();
                $sql = "INSERT INTO `authcodes` (
                    `id`, `userid`, `username`, `authhash`, `description`, `created`, `createdby`
                ) VALUES (
                    NULL,
                    '".$post_id."',
                    '".$mysql->real_escape_string($urow['username'])."',
                    '".$key."',
                    '".$mysql->real_escape_string($desc)."',
                    '".time()."',
                    'admin'
                )";
                if($mysql->query($sql)) {
                    $message = "api key added";
                } else {
                    $error = "database error";
                }
            }
        }

    } elseif($post_action === 'delete_key') {
        $key_id = isset($_POST['key_id']) ? (int)$_POST['key_id'] : 0;
        $action = 'edit';
        $target_id = $post_id;

        if($key_id <= 0) {
            $error = "invalid key";
        } else {
            if($mysql->query("DELETE FROM `authcodes` WHERE `id` = '".$key_id."' LIMIT 1")) {
                $message = "api key removed";
            } else {
                $error = "database error";
            }
        }
    }

    $_SESSION['temphash'] = hash("sha256", genrand());
    $hash = $_SESSION['temphash'];
}

htmlHeader("admin - synccit", $loggedin);

?>

<div class="twocol">
    <h2>admin</h2>
    <p><a href="<?php echo ADMINURL; ?>">all users</a></p>
    <p><a href="<?php echo ADMINURL; ?>?action=create">create user</a></p>
</div>
<div class="tencol last">

<?php if($message): ?>
    <p style="color:green;font-weight:bold"><?php echo htmlspecialchars($message); ?></p>
<?php endif; ?>
<?php if($error): ?>
    <p class="error"><?php echo htmlspecialchars($error); ?></p>
<?php endif; ?>

<?php if($action === 'list'): ?>

    <h3>users</h3>
    <div class="devicetable" style="width:100%">
    <table style="width:100%">
        <thead>
            <tr>
                <td>id</td>
                <td>username</td>
                <td>email</td>
                <td>admin</td>
                <td>created</td>
                <td>last login</td>
                <td>links</td>
                <td>actions</td>
            </tr>
        </thead>
        <tbody>
        <?php
        $users = $mysql->query("SELECT `id`,`username`,`email`,`is_admin`,`created`,`lastlogin`,`numlink` FROM `user` ORDER BY `id` ASC");
        while($u = $users->fetch_assoc()):
        ?>
            <tr>
                <td><?php echo (int)$u['id']; ?></td>
                <td><?php echo htmlspecialchars($u['username']); ?></td>
                <td><?php echo htmlspecialchars($u['email']); ?></td>
                <td><?php echo $u['is_admin'] ? 'yes' : ''; ?></td>
                <td><?php echo $u['created'] ? date('Y-m-d', $u['created']) : ''; ?></td>
                <td><?php echo $u['lastlogin'] ? date('Y-m-d', $u['lastlogin']) : 'never'; ?></td>
                <td><?php echo (int)$u['numlink']; ?></td>
                <td>
                    <a href="<?php echo ADMINURL; ?>?action=edit&amp;id=<?php echo (int)$u['id']; ?>">edit</a>
                    <?php if($u['id'] != $currentUser->id): ?>
                    &nbsp;
                    <form method="post" action="<?php echo ADMINURL; ?>" style="display:inline"
                          onsubmit="return confirm('Delete user <?php echo htmlspecialchars(addslashes($u['username']), ENT_QUOTES); ?>?')">
                        <input type="hidden" name="action" value="delete" />
                        <input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>" />
                        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($hash); ?>" />
                        <input type="submit" value="delete" class="delete" style="background:none;border:none;cursor:pointer;padding:0;font-size:inherit" />
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endwhile; ?>
        </tbody>
    </table>
    </div>

<?php elseif($action === 'create'): ?>

    <h3>create user</h3>
    <form method="post" action="<?php echo ADMINURL; ?>?action=create">
        <input type="hidden" name="action" value="create" />
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($hash); ?>" />

        <label for="a_username">username</label><br />
        <input type="text" id="a_username" name="username" value="<?php echo htmlspecialchars(isset($_POST['username']) ? $_POST['username'] : ''); ?>" class="textcreate" /><br /><br />

        <label for="a_email">email</label><br />
        <input type="text" id="a_email" name="email" value="<?php echo htmlspecialchars(isset($_POST['email']) ? $_POST['email'] : ''); ?>" class="textcreate" /><br /><br />

        <label for="a_password">password</label><br />
        <input type="password" id="a_password" name="password" class="textcreate" /><br /><br />

        <label><input type="checkbox" name="is_admin" value="1" /> admin</label><br /><br />

        <input type="submit" value="create user" class="submit" />
    </form>

<?php elseif($action === 'edit'): ?>

    <?php
    if($target_id <= 0) {
        echo '<p class="error">invalid user id</p>';
    } else {
        $euser = $mysql->query("SELECT * FROM `user` WHERE `id` = '".$target_id."' LIMIT 1")->fetch_assoc();
        if(!$euser) {
            echo '<p class="error">user not found</p>';
        } else {
            $keys = $mysql->query("SELECT * FROM `authcodes` WHERE `userid` = '".$target_id."' ORDER BY `created` DESC");
    ?>

    <h3>edit: <?php echo htmlspecialchars($euser['username']); ?></h3>

    <form method="post" action="<?php echo ADMINURL; ?>?action=edit&amp;id=<?php echo (int)$target_id; ?>">
        <input type="hidden" name="action" value="edit" />
        <input type="hidden" name="id" value="<?php echo (int)$target_id; ?>" />
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($hash); ?>" />

        <label for="e_username">username</label><br />
        <input type="text" id="e_username" name="username" value="<?php echo htmlspecialchars($euser['username']); ?>" class="textcreate" /><br /><br />

        <label for="e_email">email</label><br />
        <input type="text" id="e_email" name="email" value="<?php echo htmlspecialchars($euser['email']); ?>" class="textcreate" /><br /><br />

        <label><input type="checkbox" name="is_admin" value="1" <?php echo $euser['is_admin'] ? 'checked' : ''; ?> /> admin</label><br /><br />

        <input type="submit" value="save" class="submit" />
    </form>

    <br /><hr /><br />

    <h3>set password</h3>
    <form method="post" action="<?php echo ADMINURL; ?>?action=edit&amp;id=<?php echo (int)$target_id; ?>">
        <input type="hidden" name="action" value="password" />
        <input type="hidden" name="id" value="<?php echo (int)$target_id; ?>" />
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($hash); ?>" />

        <label for="e_password">new password</label><br />
        <input type="password" id="e_password" name="password" class="textcreate" /><br /><br />

        <input type="submit" value="set password" class="submit" />
    </form>

    <br /><hr /><br />

    <h3>api keys</h3>
    <div class="devicetable">
    <table>
        <thead>
            <tr>
                <td>device name</td>
                <td>auth code</td>
                <td>created</td>
                <td>last used</td>
                <td>rm</td>
            </tr>
        </thead>
        <tbody>
        <?php while($k = $keys->fetch_assoc()): ?>
            <tr>
                <td><?php echo htmlspecialchars($k['description']); ?></td>
                <td class="authcode"><?php echo htmlspecialchars($k['authhash']); ?></td>
                <td><?php echo $k['created'] ? date('Y-m-d', $k['created']) : ''; ?></td>
                <td><?php echo $k['lastused'] ? date('Y-m-d', $k['lastused']) : 'never'; ?></td>
                <td class="delete">
                    <form method="post" action="<?php echo ADMINURL; ?>?action=edit&amp;id=<?php echo (int)$target_id; ?>"
                          onsubmit="return confirm('Remove this key?')">
                        <input type="hidden" name="action" value="delete_key" />
                        <input type="hidden" name="id" value="<?php echo (int)$target_id; ?>" />
                        <input type="hidden" name="key_id" value="<?php echo (int)$k['id']; ?>" />
                        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($hash); ?>" />
                        <input type="submit" value="[x]" style="background:none;border:none;cursor:pointer;padding:0;font-size:inherit;color:red" />
                    </form>
                </td>
            </tr>
        <?php endwhile; ?>
        </tbody>
    </table>
    </div>

    <br />
    <span class="adddevicetitle">add api key</span><br /><br />
    <form method="post" action="<?php echo ADMINURL; ?>?action=edit&amp;id=<?php echo (int)$target_id; ?>">
        <input type="hidden" name="action" value="add_key" />
        <input type="hidden" name="id" value="<?php echo (int)$target_id; ?>" />
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($hash); ?>" />
        <input type="text" name="description" placeholder="device name" class="text" />&nbsp;
        <input type="submit" value="add key" />
    </form>

    <br /><hr /><br />

    <p style="font-size:85%">
        registered: <?php echo $euser['created'] ? date('Y-m-d H:i:s', $euser['created']) : 'unknown'; ?><br />
        last login: <?php echo $euser['lastlogin'] ? date('Y-m-d H:i:s', $euser['lastlogin']) : 'never'; ?><br />
        last ip: <?php echo htmlspecialchars($euser['lastip'] ? $euser['lastip'] : ''); ?><br />
        login attempts: <?php echo (int)$euser['loginattempts']; ?>
    </p>

    <?php if($euser['id'] != $currentUser->id): ?>
    <br />
    <form method="post" action="<?php echo ADMINURL; ?>"
          onsubmit="return confirm('Delete user <?php echo htmlspecialchars(addslashes($euser['username']), ENT_QUOTES); ?> and all their data?')">
        <input type="hidden" name="action" value="delete" />
        <input type="hidden" name="id" value="<?php echo (int)$target_id; ?>" />
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($hash); ?>" />
        <input type="submit" value="delete user" style="color:red" />
    </form>
    <?php endif; ?>

    <?php
        } // end if euser
    } // end if target_id
    ?>

<?php endif; ?>

</div>

<?php

htmlFooter();
