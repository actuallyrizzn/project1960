<?php
declare(strict_types=1);

use Project1960\View;

/** @var array{slug: string, title: string, summary: string, body: string} $topic */
$topic = $topic ?? ['slug' => '', 'title' => '', 'summary' => '', 'body' => ''];
?>
<p class="mb-2"><a class="link-secondary" href="/admin/help">← Help index</a></p>
<h1 class="h3 mb-2"><?= View::e($topic['title']) ?></h1>
<p class="text-secondary"><?= View::e($topic['summary']) ?></p>
<pre class="bg-black border border-secondary p-3 text-wrap"><?= View::e($topic['body']) ?></pre>
