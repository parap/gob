<?php

function drn() {
$total = 0;
    do { $r = random_int(1, 6); $total += $r; } while ($r === 6);
    return $total;
}

for ($i=0;$i<1000;$i++) {
$res = drn();
	if ($res > 12) {
	echo $res.'
	';
	}
}
