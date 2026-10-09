<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>laravel-jobs</title>
<style>
body { font: 15px/1.5 system-ui, sans-serif; max-width: 52rem; margin: 2rem auto; padding: 0 1rem; color: #222; }
table { border-collapse: collapse; width: 100%; } td, th { text-align: left; padding: .3rem .5rem; border-bottom: 1px solid #ddd; }
code { background: #f3f3f3; padding: 0 .25rem; } .err { color: #b00; }
</style>
</head>
<body>
<h1>laravel-jobs</h1>
<p>{{ $info['stack'] }} on <strong>{{ $info['server'] }}</strong>, release <code>{{ $info['release'] }}</code> ({{ $info['env'] }}).</p>
@if ($error)
<p class="err">{{ $error }}</p>
@endif
<ul>
<li>Scheduler heartbeat: {{ $beat ? $beat.' UTC' : 'none yet' }}</li>
<li>Jobs waiting on the Redis queue: {{ $waiting ?? 'unknown' }}</li>
<li>Zoo: <a href="/_zoo/health">health</a>, <a href="/_zoo/probe">probe</a></li>
</ul>
<h2>Recent ping-pong hops</h2>
<table>
<tr><th>At (UTC)</th><th>Trace</th><th>Step</th><th>Detail</th></tr>
@forelse ($hops as $hop)
<tr><td>{{ $hop->at }}</td><td><a href="/_zoo/trace/{{ $hop->trace }}"><code>{{ substr($hop->trace, 0, 8) }}</code></a></td><td>{{ $hop->step }}</td><td>{{ $hop->detail }}</td></tr>
@empty
<tr><td colspan="4">No hops yet. Start the chain with <code>POST /_zoo/chain/ping-pong</code>.</td></tr>
@endforelse
</table>
</body>
</html>
