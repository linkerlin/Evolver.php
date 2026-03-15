<?php
$corpus = str_repeat('LLM error: something failed. ', 5);

// 测试非贪婪匹配
preg_match_all('/(?:LLM error|"error"|"status":\s*"error")[^}]{0,200}?/i', $corpus, $matches);
echo "Non-greedy:\n";
print_r($matches);
echo "Count: " . count($matches[0]) . "\n";

// 测试使用更精确的终止符
preg_match_all('/(?:LLM error|"error"|"status":\s*"error")[^L]{0,50}/i', $corpus, $matches2);
echo "\nWith L as terminator:\n";
print_r($matches2);
echo "Count: " . count($matches2[0]) . "\n";
