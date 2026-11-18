<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="dark">
    <title>Fluxo Repasse · Laravel</title>
    <style>
        :root {
            color-scheme: dark;
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: #080808;
            color: #f4f4f5;
        }

        * { box-sizing: border-box; }

        body {
            min-height: 100vh;
            margin: 0;
            background:
                radial-gradient(circle at 74% 34%, rgba(239, 68, 68, .12), transparent 34rem),
                radial-gradient(circle at 16% 88%, rgba(112, 25, 25, .16), transparent 34rem),
                #080808;
        }

        a { color: inherit; }

        .page {
            width: min(1180px, calc(100% - 48px));
            min-height: 100vh;
            margin: 0 auto;
            display: grid;
            place-items: center;
            padding: 48px 0;
        }

        .welcome {
            width: 100%;
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(320px, .84fr);
            overflow: hidden;
            border: 1px solid rgba(255, 255, 255, .12);
            border-radius: 12px;
            background: #151515;
            box-shadow: 0 28px 80px rgba(0, 0, 0, .45);
        }

        .content {
            display: flex;
            flex-direction: column;
            justify-content: center;
            min-height: 510px;
            padding: clamp(32px, 6vw, 78px);
        }

        .eyebrow {
            margin: 0 0 28px;
            color: #a1a1aa;
            font-size: 13px;
            font-weight: 700;
            letter-spacing: .16em;
            text-transform: uppercase;
        }

        h1 {
            max-width: 520px;
            margin: 0;
            font-size: clamp(36px, 5vw, 64px);
            line-height: .98;
            letter-spacing: -.055em;
        }

        h1 span { color: #f05252; }

        .intro {
            max-width: 500px;
            margin: 24px 0 40px;
            color: #a1a1aa;
            font-size: 18px;
            line-height: 1.6;
        }

        .links {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
            max-width: 520px;
        }

        .link {
            display: flex;
            min-height: 68px;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 16px 18px;
            border: 1px solid #333337;
            border-radius: 8px;
            color: #e4e4e7;
            text-decoration: none;
            transition: border-color .2s ease, background .2s ease, transform .2s ease;
        }

        .link:hover {
            border-color: #f05252;
            background: #202020;
            transform: translateY(-2px);
        }

        .link small {
            display: block;
            margin-top: 5px;
            color: #71717a;
            font-size: 12px;
        }

        .arrow { color: #f05252; font-size: 22px; }

        .art {
            position: relative;
            display: grid;
            min-height: 510px;
            place-items: center;
            overflow: hidden;
            border-left: 1px solid rgba(255, 255, 255, .1);
            background:
                linear-gradient(135deg, rgba(239, 68, 68, .92), rgba(127, 29, 29, .8)),
                #991b1b;
        }

        .art::before,
        .art::after {
            position: absolute;
            width: 260px;
            height: 260px;
            border: 1px solid rgba(255, 255, 255, .2);
            content: "";
            transform: rotate(45deg);
        }

        .art::before { top: -110px; right: -80px; }
        .art::after { bottom: -110px; left: -80px; }

        .brand {
            position: relative;
            z-index: 1;
            display: grid;
            place-items: center;
            text-align: center;
        }

        .brand-mark {
            width: 150px;
            height: 150px;
            margin-bottom: 22px;
            border: 8px solid rgba(255, 255, 255, .9);
            border-radius: 50%;
            box-shadow: 20px -16px 0 -8px rgba(255, 255, 255, .2), -22px 16px 0 -10px rgba(82, 13, 13, .4);
            transform: rotate(-14deg);
        }

        .brand-title {
            font-size: clamp(42px, 6vw, 78px);
            font-weight: 800;
            letter-spacing: -.08em;
            line-height: .85;
        }

        .brand-subtitle {
            margin-top: 14px;
            font-size: 14px;
            font-weight: 700;
            letter-spacing: .38em;
            text-transform: uppercase;
        }

        .version {
            position: absolute;
            right: 26px;
            bottom: 22px;
            color: rgba(255, 255, 255, .72);
            font-size: 12px;
        }

        @media (max-width: 760px) {
            .page { width: min(100% - 24px, 560px); padding: 24px 0; }
            .welcome { grid-template-columns: 1fr; }
            .content { min-height: auto; padding: 34px 26px 28px; }
            .links { grid-template-columns: 1fr; }
            .art { min-height: 320px; border-top: 1px solid rgba(255, 255, 255, .1); border-left: 0; }
            .brand-mark { width: 96px; height: 96px; margin-bottom: 16px; }
        }
    </style>
</head>
<body>
<main class="page">
    <section class="welcome" aria-labelledby="welcome-title">
        <div class="content">
            <p class="eyebrow">Fluxo Repasse · Laravel</p>
            <h1 id="welcome-title">Sua operação começa <span>aqui.</span></h1>
            <p class="intro">
                A API está instalada e pronta para receber as operações do Fluxo Repasse.
                Consulte a documentação ou acesse o painel administrativo.
            </p>
            <nav class="links" aria-label="Links úteis">
                <a class="link" href="https://laravel.com/docs" target="_blank" rel="noreferrer">
                    <span>Leia a documentação <small>Laravel Docs</small></span>
                    <span class="arrow" aria-hidden="true">↗</span>
                </a>
                <a class="link" href="https://laracasts.com" target="_blank" rel="noreferrer">
                    <span>Aprenda com tutoriais <small>Laracasts</small></span>
                    <span class="arrow" aria-hidden="true">↗</span>
                </a>
                <a class="link" href="{{ url('/api/v1/ready') }}">
                    <span>Verificar API <small>Readiness check</small></span>
                    <span class="arrow" aria-hidden="true">↗</span>
                </a>
                <a class="link" href="{{ url('/up') }}">
                    <span>Verificar serviço <small>Health check</small></span>
                    <span class="arrow" aria-hidden="true">↗</span>
                </a>
            </nav>
        </div>
        <div class="art" aria-hidden="true">
            <div class="brand">
                <div class="brand-mark"></div>
                <div class="brand-title">Fluxo</div>
                <div class="brand-subtitle">Repasse</div>
            </div>
            <div class="version">Laravel {{ app()->version() }}</div>
        </div>
    </section>
</main>
</body>
</html>
