<!doctype html>
<html lang="pt-PT">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex">
    <title>Piquet — tens {{ $valor }} € para o primeiro serviço</title>
    <meta property="og:title" content="Tens {{ $valor }} € para o primeiro serviço na Piquet">
    <meta property="og:description" content="Técnicos em casa para canalização, eletricidade, limpezas e muito mais.">
    <style>
        :root { --ambar: #FABB5B; --escuro: #1B1B1B; --creme: #FAF7F2; --cinza: #6E6E6E; }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: var(--creme); color: var(--escuro);
            min-height: 100vh; display: flex; justify-content: center;
            padding: max(24px, env(safe-area-inset-top)) 16px 32px;
        }
        main { width: 100%; max-width: 420px; }
        .marca { font-weight: 800; letter-spacing: 3px; font-size: 18px; text-align: center; margin-bottom: 20px; }
        .destaque {
            background: var(--ambar); border-radius: 28px; padding: 28px 20px 22px; text-align: center;
            position: relative; overflow: hidden;
        }
        .destaque::after {
            content: ""; position: absolute; width: 180px; height: 180px; border-radius: 50%;
            background: rgba(255,255,255,.18); top: -60px; right: -50px;
        }
        .presente {
            width: 56px; height: 56px; border-radius: 50%; background: var(--escuro); color: var(--ambar);
            display: flex; align-items: center; justify-content: center; margin: 0 auto 12px; font-size: 26px;
        }
        h1 { font-size: 26px; line-height: 1.2; position: relative; z-index: 1; }
        .sub { margin-top: 8px; font-size: 15px; color: #3b3b3b; position: relative; z-index: 1; }
        .bilhete { background: #fff; border-radius: 28px; margin-top: 16px; box-shadow: 0 4px 12px rgba(0,0,0,.05); }
        .bilhete .topo { padding: 20px 20px 16px; }
        .rotulo { font-size: 12px; font-weight: 700; letter-spacing: 1.5px; color: var(--cinza); }
        .codigo { font-size: 30px; font-weight: 800; letter-spacing: 6px; margin-top: 4px; }
        .picotado { display: flex; align-items: center; }
        .picotado span { width: 22px; height: 22px; border-radius: 50%; background: var(--creme); }
        .picotado span:first-child { margin-left: -11px; } .picotado span:last-child { margin-right: -11px; }
        .picotado i { flex: 1; border-top: 1.5px dashed #E4E3E3; margin: 0 6px; }
        .bilhete .base { padding: 16px 20px 20px; }
        .botao {
            display: flex; align-items: center; justify-content: center; width: 100%; border: 0; cursor: pointer;
            background: var(--escuro); color: var(--ambar); font-weight: 700; font-size: 16px;
            border-radius: 16px; padding: 16px; text-decoration: none; font-family: inherit;
        }
        .botao + .botao { margin-top: 10px; }
        .botao.claro { background: #F4F2EE; color: var(--escuro); }
        .nota { margin-top: 12px; font-size: 13px; color: var(--cinza); text-align: center; line-height: 1.4; }
        .ok { color: #059669; font-weight: 600; display: none; }
    </style>
</head>
<body>
<main>
    <div class="marca">PIQUET</div>

    <section class="destaque">
        <div class="presente" aria-hidden="true">🎁</div>
        <h1>Tens {{ $valor }} € para o primeiro serviço</h1>
        <p class="sub">Técnicos em casa para canalização, eletricidade, limpezas e muito mais.</p>
    </section>

    <section class="bilhete">
        @if ($codigo)
            <div class="topo">
                <div class="rotulo">O TEU CÓDIGO</div>
                <div class="codigo" id="codigo">{{ $codigo }}</div>
            </div>
            <div class="picotado" aria-hidden="true"><span></span><i></i><span></span></div>
        @endif

        <div class="base">
            @if ($eIphone)
                {{-- A App Store não passa nada à app: copia-se o código antes de sair,
                     e na app cola-se com um toque. --}}
                <button class="botao" id="instalar" type="button">
                    {{ $codigo ? 'Copiar código e instalar' : 'Instalar a Piquet' }}
                </button>
                <a class="botao claro" href="{{ $abrirApp }}">Já tenho a app</a>
                <p class="nota" id="nota">
                    @if ($codigo)
                        Na app, cola o código ao pedir o serviço.
                    @endif
                </p>
                <p class="nota ok" id="copiado">Código copiado. Na app, cola-o ao pedir o serviço.</p>
            @else
                <a class="botao" href="{{ $appStoreUrl }}">App Store (iPhone)</a>
                <a class="botao" href="{{ $playUrl }}">Google Play (Android)</a>
                @if ($codigo)
                    <p class="nota">Na app, escreve o código ao pedir o serviço.</p>
                @endif
            @endif
        </div>
    </section>
</main>

@if ($eIphone)
<script>
    (function () {
        var codigo = @json($codigo);
        var loja = @json($appStoreUrl, JSON_UNESCAPED_SLASHES);
        document.getElementById('instalar').addEventListener('click', function () {
            var seguir = function () { window.location.href = loja; };
            if (!codigo || !navigator.clipboard) { seguir(); return; }
            navigator.clipboard.writeText(codigo).then(function () {
                document.getElementById('nota').style.display = 'none';
                document.getElementById('copiado').style.display = 'block';
                setTimeout(seguir, 600);
            }, seguir);
        });
    })();
</script>
@endif
</body>
</html>
