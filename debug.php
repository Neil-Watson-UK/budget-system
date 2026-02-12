<?php
session_start();
?>
<!DOCTYPE html>
<html>
<body>
<h1>POST Method Test</h1>

<!-- Test 1: Simple form -->
<form method="POST" action="test_post_receiver.php" id="form1">
    <input type="hidden" name="test" value="form1">
    <button type="submit">Test POST</button>
</form>

<!-- Test 2: Form with button type="submit" -->
<form method="POST" action="test_post_receiver.php" id="form2">
    <input type="hidden" name="test" value="form2">
    <button type="submit" onclick="alert('Button clicked'); return true;">With onclick</button>
</form>

<!-- Test 3: Manual JavaScript submit -->
<form method="POST" action="test_post_receiver.php" id="form3">
    <input type="hidden" name="test" value="form3">
    <button type="button" onclick="document.getElementById('form3').submit();">JS Submit</button>
</form>

</body>
</html>