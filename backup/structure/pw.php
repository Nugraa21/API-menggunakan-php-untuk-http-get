<?php
$hash = '$2y$10$j7J3rcgqir1XR0Xv7LhfHOmInPq2E7mxLwdFafH9eEpfJDhATGS82';

$test = '081328';

if (password_verify($test, $hash)) {
    echo "PASSWORD BENAR";
} else {
    echo "PASSWORD SALAH";
}
?>



