<?php
// Redirect to new Group Stage structure
header("Location: tournament-groups.php?id=" . ($_GET['id'] ?? ''));
exit();
?>
