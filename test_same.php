<?php
$a = ['data' => 'test'];
$b = ['data' => 'test'];
$c = $a;

echo "\$a === \$b: " . ($a === $b ? 'true' : 'false') . "\n";
echo "\$a !== \$b: " . ($a !== $b ? 'true' : 'false') . "\n";
echo "\$a === \$c: " . ($a === $c ? 'true' : 'false') . "\n";
echo "\$a !== \$c: " . ($a !== $c ? 'true' : 'false') . "\n";

// Create a new array
$d = [];
foreach ($a as $k => $v) {
    $d[$k] = $v;
}
echo "\$a === \$d: " . ($a === $d ? 'true' : 'false') . "\n";
echo "\$a !== \$d: " . ($a !== $d ? 'true' : 'false') . "\n";
