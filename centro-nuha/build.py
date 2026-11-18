#!/usr/bin/env python3
"""Genera la web estática de Centro Nuha (centronuha.es) en ./web

Las imágenes van dentro de web/assets/images/ (recuperadas de la copia de seguridad de la web antigua) y se incluyen en el zip.
(la web antigua). Por eso al subir la web nueva NO hay que borrar la carpeta assets.

  python3 build.py            -> web/  (para la raíz de centronuha.es)
  python3 build.py preview    -> preview/ (imágenes con dirección completa, para verla fuera del hosting)
"""
import json, sys, shutil, datetime, html
from pathlib import Path

MODO = sys.argv[1] if len(sys.argv) > 1 else 'web'
AQUI = Path(__file__).resolve().parent
OUT = AQUI / MODO
BASE = 'https://www.centronuha.es/'
IMG = (BASE if MODO == 'preview' else '') + 'assets/images/'
HOY = datetime.date.today().isoformat()

TEL = '983 35 47 63'
TEL_LINK = '+34983354763'
EMAIL = 'centrolosarces@gmail.com'
DIR = 'Calle Gregorio Fernández, 4'
CP = '47006'
MAPS = 'https://www.google.com/maps/search/?api=1&query=Calle+Gregorio+Fern%C3%A1ndez+4+Valladolid'
NH = 'https://www.nutriendohabitos.es/'
# Dirección del Apps Script que envía el formulario al correo del centro (ver centro-nuha/LEEME.md)
_FU = Path(__file__).parent / 'formulario_url.txt'
FORM_URL = _FU.read_text(encoding='utf-8').strip() if _FU.exists() else ''

MENU = [('index.html', 'Inicio'), ('fisioterapia.html', 'Fisioterapia'), ('acupuntura.html', 'Acupuntura'),
        ('nutricion.html', 'Nutrición'), ('psicologia.html', 'Psicología'), ('quienes-somos.html', 'Quiénes somos'),
        ('instalaciones.html', 'Instalaciones'), ('contacto.html', 'Contacto')]

CSS = r"""
:root{--ink:#1d2a2a;--ink2:#4a5a58;--sage:#4f7465;--sage-d:#3b5a4e;--sand:#f5f0e8;--sand2:#ebe3d6;--line:#ddd3c3;--terra:#b0603c;--white:#fff}
*{box-sizing:border-box}html{scroll-behavior:smooth}
body{margin:0;background:var(--sand);color:var(--ink);font:17px/1.65 'Lora',Georgia,serif;-webkit-font-smoothing:antialiased}
a{color:var(--sage-d)}img{max-width:100%;display:block}
h1,h2,h3,.nav,.btn,.eyebrow,.foot h4{font-family:'Montserrat',system-ui,sans-serif}
h1{font-size:clamp(2rem,5vw,3.3rem);line-height:1.12;margin:.2em 0 .35em;font-weight:700;letter-spacing:-.01em}
h2{font-size:clamp(1.5rem,3.2vw,2.1rem);line-height:1.2;margin:0 0 .6em;font-weight:700}
h3{font-size:1.15rem;margin:0 0 .4em;font-weight:700}
.wrap{max-width:1120px;margin:0 auto;padding:0 20px}
.eyebrow{text-transform:uppercase;letter-spacing:.16em;font-size:.75rem;font-weight:700;color:var(--terra)}
header.top{position:sticky;top:0;z-index:20;background:rgba(245,240,232,.94);backdrop-filter:blur(8px);border-bottom:1px solid var(--line)}
.top .wrap{display:flex;align-items:center;justify-content:space-between;gap:16px;min-height:68px}
.brand{flex-shrink:0;white-space:nowrap;display:flex;align-items:center;gap:10px;text-decoration:none;color:var(--ink);font:700 1.15rem 'Montserrat',sans-serif;letter-spacing:.04em}
.brand img{width:40px;height:auto}
.brand small{display:block;white-space:nowrap;font:500 .66rem 'Montserrat',sans-serif;letter-spacing:.14em;color:var(--ink2);text-transform:uppercase}
.nav{display:flex;gap:4px;align-items:center;font-size:.85rem;font-weight:600}
.nav a{color:var(--ink);text-decoration:none;padding:8px 10px;border-radius:8px;white-space:nowrap}
.nav a:hover,.nav a[aria-current]{background:var(--sand2)}
.nav a.cta{background:var(--sage);color:#fff}.nav a.cta:hover{background:var(--sage-d)}
.burger{display:none;background:none;border:1px solid var(--line);border-radius:8px;padding:8px 10px;font:600 .85rem 'Montserrat',sans-serif;color:var(--ink)}
@media(max-width:1280px){.burger{display:block}.nav{display:none;position:absolute;left:0;right:0;top:68px;background:var(--sand);flex-direction:column;align-items:stretch;padding:10px 20px 18px;border-bottom:1px solid var(--line)}.nav.open{display:flex}.nav a{padding:12px}}
.hero{position:relative;color:#fff;background:#24302d center/cover no-repeat;min-height:min(78vh,640px);display:flex;align-items:flex-end}
.hero::before{content:"";position:absolute;inset:0;background:linear-gradient(180deg,rgba(20,28,26,.25),rgba(20,28,26,.78))}
.hero .wrap{position:relative;padding-top:120px;padding-bottom:64px}
.hero p.lead{font-size:1.15rem;max-width:40rem;margin:0 0 1.4em;color:#f1ece4}
.hero .eyebrow{color:#f2c9a8}
.hero.small{min-height:380px}
.crumb{font:600 .78rem 'Montserrat',sans-serif;color:#e9e1d4;margin-bottom:6px}.crumb a{color:#fff}
.btn{display:inline-block;background:var(--terra);color:#fff;text-decoration:none;font-weight:700;font-size:.9rem;padding:13px 22px;border-radius:999px;margin:4px 8px 4px 0}
.btn:hover{filter:brightness(1.07)}.btn.ghost{background:transparent;border:2px solid currentColor;color:inherit}
.btn.sage{background:var(--sage)}
section.blk{padding:72px 0}section.alt{background:var(--white)}
.grid3{display:grid;grid-template-columns:repeat(3,1fr);gap:22px}
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:36px;align-items:center}
.grid3.g4{grid-template-columns:repeat(4,1fr)}
@media(max-width:1100px){.grid3.g4{grid-template-columns:repeat(2,1fr)}}
@media(max-width:860px){.grid3,.grid3.g4,.grid2{grid-template-columns:1fr}}
.card{background:var(--white);border:1px solid var(--line);border-radius:18px;overflow:hidden;display:flex;flex-direction:column}
section.alt .card{background:var(--sand)}
.card img{aspect-ratio:3/2;object-fit:cover;width:100%}
.card .in{padding:20px 22px 24px;display:flex;flex-direction:column;flex:1}
.card .in p{margin:0 0 1em;color:var(--ink2)}
.card .more{margin-top:auto;font:700 .85rem 'Montserrat',sans-serif;text-decoration:none}
.pic{border-radius:18px;overflow:hidden;aspect-ratio:4/3}.pic img{width:100%;height:100%;object-fit:cover}
.list{list-style:none;padding:0;margin:0;display:grid;gap:12px}
.list li{background:var(--white);border:1px solid var(--line);border-radius:14px;padding:14px 18px}
section.alt .list li{background:var(--sand)}
.list li b{font-family:'Montserrat',sans-serif;font-size:.95rem}
.tiles{display:grid;grid-template-columns:repeat(4,1fr);gap:16px}
@media(max-width:860px){.tiles{grid-template-columns:1fr 1fr}}
@media(max-width:520px){.tiles{grid-template-columns:1fr}}
.tile{background:var(--white);border:1px solid var(--line);border-radius:16px;padding:18px}
section.alt .tile{background:var(--sand)}
.tile h3{font-size:1rem}.tile p{margin:0;color:var(--ink2);font-size:.95rem}
.team{display:grid;grid-template-columns:repeat(3,1fr);gap:22px}
@media(max-width:860px){.team{grid-template-columns:1fr}}
.pro{background:var(--white);border:1px solid var(--line);border-radius:18px;padding:24px}
.pro .role{font:700 .78rem 'Montserrat',sans-serif;text-transform:uppercase;letter-spacing:.12em;color:var(--terra)}
.pro ul{padding-left:1.1em;margin:.6em 0 0;color:var(--ink2)}
.faq details{background:var(--white);border:1px solid var(--line);border-radius:14px;padding:14px 18px;margin-bottom:10px}
section.alt .faq details{background:var(--sand)}
.faq summary{cursor:pointer;font:700 1rem 'Montserrat',sans-serif}
.faq p{margin:.6em 0 0;color:var(--ink2)}
.band{background:var(--sage);color:#fff;border-radius:22px;padding:36px;display:flex;flex-wrap:wrap;gap:20px;align-items:center;justify-content:space-between}
.band h2{margin:0 0 .2em}.band p{margin:0;color:#e8efe9}
.band .btn{background:#fff;color:var(--sage-d)}
.contact{display:grid;grid-template-columns:repeat(3,1fr);gap:16px}
@media(max-width:860px){.contact{grid-template-columns:1fr}}
.contact a.c{display:block;background:var(--white);border:1px solid var(--line);border-radius:16px;padding:20px;text-decoration:none;color:var(--ink)}
.contact a.c b{display:block;font:700 .78rem 'Montserrat',sans-serif;text-transform:uppercase;letter-spacing:.12em;color:var(--terra);margin-bottom:4px}
.contact a.c span{font-size:1.1rem}
.note{font-size:.92rem;color:var(--ink2)}
.form{max-width:760px;margin-top:14px;display:grid;gap:14px}
.form .fr{display:grid;grid-template-columns:1fr 1fr;gap:14px}
@media(max-width:640px){.form .fr{grid-template-columns:1fr}}
.form label{display:grid;gap:6px;font-size:.88rem;font-weight:600;color:var(--ink)}
.form input,.form select,.form textarea{font:inherit;font-weight:400;font-size:1rem;padding:11px 13px;border:1px solid #cfc6b8;border-radius:10px;background:#fff;color:var(--ink);width:100%;box-sizing:border-box}
.form input:focus,.form select:focus,.form textarea:focus{outline:2px solid var(--sage-d);outline-offset:1px}
.form .chk{display:flex;gap:10px;align-items:flex-start;font-weight:400}
.form .chk input{width:auto;margin-top:3px}
.form .hp{position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden}
.form .btn{justify-self:start;border:0;cursor:pointer}
.form .btn[disabled]{opacity:.6;cursor:wait}
.fmsg{margin:0;font-size:.95rem}.fmsg.ok{color:var(--sage-d);font-weight:600}.fmsg.err{color:#a33}
footer.foot{background:var(--ink);color:#d9d3c8;padding:54px 0 30px;font-size:.95rem}
.foot .cols{display:grid;grid-template-columns:1.3fr 1fr 1fr;gap:30px}
@media(max-width:760px){.foot .cols{grid-template-columns:1fr}}
.foot h4{color:#fff;font-size:.8rem;text-transform:uppercase;letter-spacing:.14em;margin:0 0 12px}
.foot a{color:#f1ece4;text-decoration:none}.foot a:hover{text-decoration:underline}
.foot ul{list-style:none;padding:0;margin:0;display:grid;gap:6px}
.foot .legal{border-top:1px solid #34413f;margin-top:34px;padding-top:18px;font-size:.82rem;color:#a9a397;display:flex;flex-wrap:wrap;gap:12px;justify-content:space-between}
.prose{max-width:46rem}.prose h2{margin-top:1.4em}
"""

JS_FORM = r"""(function(){var f=document.getElementById('f-contacto');if(!f)return;var m=document.getElementById('f-msg'),U=f.getAttribute('data-url');
f.addEventListener('submit',function(e){e.preventDefault();m.className='fmsg';
if(f.web.value){return;}
if(!f.nombre.value.trim()||!f.telefono.value.trim()||!f.mensaje.value.trim()){m.className='fmsg err';m.textContent='Rellena nombre, teléfono y mensaje.';return;}
if(!f.acepto.checked){m.className='fmsg err';m.textContent='Marca la casilla de protección de datos para poder enviarlo.';return;}
if(!U){m.className='fmsg err';m.textContent='El formulario aún no está activo. Llámanos al __TEL__ y te atendemos.';return;}
var b=f.querySelector('button');b.disabled=true;m.textContent='Enviando…';
var d=new URLSearchParams();['nombre','telefono','email','especialidad','mensaje'].forEach(function(k){d.append(k,f[k].value.trim())});d.append('pagina',location.href);
fetch(U,{method:'POST',mode:'no-cors',body:d}).then(function(){f.reset();m.className='fmsg ok';m.textContent='¡Mensaje enviado! Te responderemos lo antes posible.';})
.catch(function(){m.className='fmsg err';m.textContent='No se ha podido enviar. Llámanos al __TEL__.';}).then(function(){b.disabled=false;});});})();""".replace('__TEL__', TEL)
JS = "document.querySelector('.burger').addEventListener('click',function(){var n=document.querySelector('.nav');var o=n.classList.toggle('open');this.setAttribute('aria-expanded',o)});"

NEGOCIO = {
    '@context': 'https://schema.org',
    '@type': 'MedicalClinic',
    '@id': BASE + '#centro',
    'name': 'Centro Nuha',
    'alternateName': 'NUHA – Salud Integrativa',
    'description': 'Centro de salud en el centro de Valladolid con fisioterapia y osteopatía, acupuntura y nutrición.',
    'url': BASE,
    'telephone': '+34 983 35 47 63',
    'email': EMAIL,
    'image': BASE + 'assets/images/mbr-1626x1080.jpg',
    'logo': BASE + 'assets/images/logo-265x244.png',
    'address': {'@type': 'PostalAddress', 'streetAddress': DIR, 'postalCode': CP, 'addressLocality': 'Valladolid',
                'addressRegion': 'Valladolid', 'addressCountry': 'ES'},
    'hasMap': MAPS,
    'areaServed': {'@type': 'City', 'name': 'Valladolid'},
    'medicalSpecialty': ['Physiotherapy', 'DietNutrition'],
    'availableService': [
        {'@type': 'MedicalTherapy', 'name': 'Fisioterapia y osteopatía', 'url': BASE + 'fisioterapia.html'},
        {'@type': 'MedicalTherapy', 'name': 'Acupuntura', 'url': BASE + 'acupuntura.html'},
        {'@type': 'MedicalTherapy', 'name': 'Nutrición y dietética', 'url': BASE + 'nutricion.html'},
        {'@type': 'MedicalTherapy', 'name': 'Psicología', 'url': BASE + 'psicologia.html'}],
    'subOrganization': {'@type': 'MedicalBusiness', 'name': 'Nutriendo Hábitos', 'url': NH},
}


def pagina(archivo, titulo, desc, cuerpo, extra_ld=None, robots='index,follow'):
    actual = archivo
    nav = ''.join(
        f'<a href="{h}"{" aria-current=\"page\"" if h == actual else ""}{" class=\"cta\"" if h == "contacto.html" else ""}>{t}</a>'
        for h, t in MENU)
    url = BASE + ('' if archivo == 'index.html' else archivo)
    lds = [NEGOCIO] + (extra_ld or [])
    ld = ''.join(f'<script type="application/ld+json">{json.dumps(x, ensure_ascii=False)}</script>' for x in lds)
    return f"""<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{html.escape(titulo)}</title>
<meta name="description" content="{html.escape(desc)}">
<meta name="robots" content="{robots}">
<link rel="canonical" href="{url}">
<meta property="og:type" content="website"><meta property="og:locale" content="es_ES">
<meta property="og:title" content="{html.escape(titulo)}"><meta property="og:description" content="{html.escape(desc)}">
<meta property="og:url" content="{url}"><meta property="og:image" content="{BASE}assets/images/mbr-1626x1080.jpg">
<link rel="icon" href="{IMG}logo-138x128.png">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Lora:wght@400;500;600&family=Montserrat:wght@500;600;700&display=swap" rel="stylesheet">
<style>{CSS}</style>
{ld}
</head>
<body>
<header class="top"><div class="wrap">
<a class="brand" href="index.html"><img src="{IMG}logo-138x128.png" alt="" width="40" height="37"><span>CENTRO NUHA<small>Salud integrativa · Valladolid</small></span></a>
<button class="burger" aria-expanded="false" aria-label="Abrir menú">Menú</button>
<nav class="nav" aria-label="Principal">{nav}</nav>
</div></header>
<main>
{cuerpo}
</main>
<footer class="foot"><div class="wrap">
<div class="cols">
<div><h4>Centro Nuha</h4><p>Fisioterapia y osteopatía, acupuntura, nutrición y psicología en el centro de Valladolid, a pocos minutos del Campo Grande.</p>
<p>{DIR}<br>{CP} Valladolid</p></div>
<div><h4>Especialidades</h4><ul><li><a href="fisioterapia.html">Fisioterapia y osteopatía</a></li><li><a href="acupuntura.html">Acupuntura</a></li><li><a href="nutricion.html">Nutrición</a></li><li><a href="psicologia.html">Psicología</a></li><li><a href="cursos-talleres.html">Talleres</a></li></ul></div>
<div><h4>Contacto</h4><ul><li><a href="tel:{TEL_LINK}">{TEL}</a></li><li><a href="contacto.html#formulario">Escríbenos</a></li><li><a href="{MAPS}" target="_blank" rel="noopener">Cómo llegar</a></li><li><a href="{NH}" target="_blank" rel="noopener">Nutriendo Hábitos</a></li></ul></div>
</div>
<div class="legal"><span>© {datetime.date.today().year} Centro Nuha · Valladolid</span><span><a href="quienes-somos.html">Quiénes somos</a> · <a href="instalaciones.html">Instalaciones</a> · <a href="proteccion-de-datos.html">Protección de datos</a></span></div>
</div></footer>
<script>{JS}{JS_FORM if 'f-contacto' in cuerpo else ''}</script>
</body>
</html>
"""


def hero(img, ey, h1, lead, crumb=None, botones=True, small=True):
    c = f'<div class="crumb"><a href="index.html">Inicio</a> · {crumb}</div>' if crumb else ''
    b = (f'<a class="btn" href="tel:{TEL_LINK}">Llamar al {TEL}</a><a class="btn ghost" href="contacto.html">Cómo pedir cita</a>' if botones else '')
    return f'<section class="hero{" small" if small else ""}" style="background-image:url(\'{IMG}{img}\')"><div class="wrap">{c}<div class="eyebrow">{ey}</div><h1>{h1}</h1><p class="lead">{lead}</p>{b}</div></section>'


def faq(items):
    h = ''.join(f'<details><summary>{q}</summary><p>{a}</p></details>' for q, a in items)
    ld = {'@context': 'https://schema.org', '@type': 'FAQPage', 'mainEntity': [
        {'@type': 'Question', 'name': q, 'acceptedAnswer': {'@type': 'Answer', 'text': a}} for q, a in items]}
    return f'<div class="faq">{h}</div>', ld


def migas(*pares):
    return {'@context': 'https://schema.org', '@type': 'BreadcrumbList', 'itemListElement': [
        {'@type': 'ListItem', 'position': i + 1, 'name': n, 'item': BASE + u} for i, (n, u) in enumerate(pares)]}


BANDA = f'<section class="blk"><div class="wrap"><div class="band"><div><h2>¿Pedimos cita?</h2><p>Llámanos y te orientamos sobre la especialidad que mejor encaja contigo.</p></div><div><a class="btn" href="tel:{TEL_LINK}">{TEL}</a><a class="btn ghost" href="contacto.html#formulario">Escribir un mensaje</a></div></div></div></section>'

PAGS = {}

# ------------------------------------------------------------------ inicio
f_html, f_ld = faq([
    ('¿Dónde está Centro Nuha?', f'En {DIR}, en el centro de Valladolid, a pocos minutos andando del Campo Grande.'),
    ('¿Qué especialidades hay en el centro?', 'Fisioterapia y osteopatía con Amaya Arnaiz, acupuntura con el médico acupuntor Adolfo Argüello Fernández, nutrición con dietista-nutricionista colegiada (Nutriendo Hábitos) y psicología.'),
    ('¿Cómo pido cita?', f'Cada profesional gestiona su agenda: fisioterapia con Amaya Arnaiz en el 606 926 228 (teléfono y WhatsApp); nutrición con Andrea Argüello en el 669 87 70 52 o con el formulario de nutriendohabitos.es; acupuntura y dudas generales en el {TEL}.'),
    ('¿Se pueden combinar tratamientos?', 'Sí. Al estar en el mismo centro, los profesionales pueden coordinarse cuando un mismo problema se beneficia de varios enfoques, por ejemplo dolor de espalda con fisioterapia y acupuntura, o digestiones pesadas con nutrición y osteopatía visceral.'),
])
PAGS['index.html'] = ('Centro Nuha · Fisioterapia, acupuntura, nutrición y psicología en Valladolid',
    'Centro de salud en el centro de Valladolid: fisioterapia y osteopatía, acupuntura con médico acupuntor, nutricionista y psicología. Calle Gregorio Fernández 4. Pide cita en el 983 35 47 63.',
    hero('mbr-1626x1080.jpg', 'Centro Nuha · Valladolid', 'Fisioterapia, acupuntura, nutrición y psicología en el centro de Valladolid',
         'Un lugar para la salud. Profesionales sanitarios con muchos años de experiencia, en un mismo centro y a pocos minutos del Campo Grande.', small=False) +
    f'''<section class="blk"><div class="wrap">
<div class="eyebrow">Especialidades</div><h2>Cuatro formas de cuidarte, un mismo centro</h2>
<div class="grid3 g4">
<article class="card"><img src="{IMG}mbr-1-600x400.jpg" alt="Sesión de fisioterapia en Centro Nuha, Valladolid" loading="lazy"><div class="in"><h3>Fisioterapia y osteopatía</h3><p>Con Amaya Arnaiz, fisioterapeuta y osteópata: dolor de espalda, dolor persistente, reeducación postural, mandíbula y cefaleas, osteopatía visceral y fisioterapia para músicos.</p><a class="more" href="fisioterapia.html">Fisioterapia en Valladolid →</a></div></article>
<article class="card"><img src="{IMG}mbr-600x495.jpg" alt="Acupuntura en Centro Nuha, Valladolid" loading="lazy"><div class="in"><h3>Acupuntura</h3><p>Con Adolfo Argüello, médico acupuntor licenciado en Medicina y Cirugía y diplomado en Medicina Tradicional China. Lumbociáticas, migrañas, ansiedad y acompañamiento en el embarazo.</p><a class="more" href="acupuntura.html">Acupuntura en Valladolid →</a></div></article>
<article class="card"><img src="{IMG}mbr-600x337.jpg" alt="Consulta de nutrición en Centro Nuha, Valladolid" loading="lazy"><div class="in"><h3>Nutrición</h3><p>Con Andrea Argüello, dietista-nutricionista (Nutriendo Hábitos): aprender a comer sin dietas estrictas, nutrición deportiva, oncológica y digestiva. Presencial y online.</p><a class="more" href="nutricion.html">Nutricionista en Valladolid →</a></div></article>
<article class="card"><img src="{IMG}mbr-2000x1333.jpg" alt="Psicología en Centro Nuha, Valladolid" loading="lazy"><div class="in"><h3>Psicología</h3><p>Un espacio para cuidar también la mente: ansiedad y estrés, estado de ánimo, autoestima y momentos de cambio, en coordinación con el resto del equipo.</p><a class="more" href="psicologia.html">Psicología en Valladolid →</a></div></article>
</div></div></section>
<section class="blk alt"><div class="wrap grid2">
<div><div class="eyebrow">Por qué Centro Nuha</div><h2>Profesionales que se coordinan entre sí</h2>
<p>Centro Nuha reúne en un mismo espacio fisioterapia, acupuntura, nutrición y psicología. Cada profesional trabaja en su especialidad y, cuando tiene sentido, se coordina con los demás para atender el problema desde su origen y no solo el síntoma.</p>
<ul class="list"><li><b>Atención individual.</b> Valoramos a cada persona y sus circunstancias para adaptar el tratamiento.</li><li><b>Experiencia.</b> Más de 10 años atendiendo pacientes en Valladolid.</li><li><b>En el centro.</b> {DIR}, a pocos minutos del Campo Grande.</li></ul></div>
<div class="pic"><img src="{IMG}mbr-1920x1440.jpg" alt="Tratamiento en Centro Nuha, Valladolid" loading="lazy"></div>
</div></section>
<section class="blk"><div class="wrap"><div class="eyebrow">Preguntas frecuentes</div><h2>Lo que más nos preguntan</h2>{f_html}</div></section>
''' + BANDA, [f_ld])

# ------------------------------------------------------------------ fisioterapia
AM = 'https://amayaarnaiz.es/'
AM_TEL, AM_TEL_LINK, AM_MAIL = '606 926 228', '+34606926228', 'contacto@amayaarnaiz.es'
AM_WA = 'https://wa.me/34606926228'
f_html, f_ld = faq([
    ('¿Quién es la fisioterapeuta de Centro Nuha?', 'Amaya Arnaiz Carro, fisioterapeuta desde 2010 y osteópata. Diplomada en Fisioterapia por la Universidad de Salamanca, C.O. en Osteopatía por la Escuela de Osteopatía de Madrid y experta en Terapia Fascial.'),
    ('¿Qué diferencia hay entre fisioterapia y osteopatía?', 'La fisioterapia trata lesiones y dolor del aparato locomotor; la osteopatía añade un abordaje global (músculo-esquelético, craneal y visceral). En la consulta se combinan técnicas manuales, ejercicio y pautas personalizadas, entendiendo el cuerpo como una unidad.'),
    ('¿Cuánto dura una sesión?', 'La primera consulta dura alrededor de una hora (historia clínica, exploración y objetivos). Las sesiones de seguimiento, unos 50 minutos.'),
    ('¿Tratáis el dolor crónico o persistente?', 'Sí. El dolor persistente se aborda desde un enfoque biopsicosocial: terapia manual, consciencia y trabajo corporal, y explicación de los mecanismos que producen y mantienen el dolor.'),
    ('¿Hacéis fisioterapia para músicos?', 'Sí, es una de las especialidades de Amaya: prevención y tratamiento de los problemas musculoesqueléticos ligados a tocar o cantar, y de la ansiedad escénica.'),
    ('¿Cómo pido cita de fisioterapia en Valladolid?', f'Llamando o por WhatsApp al {AM_TEL}, o escribiendo a {AM_MAIL}. De lunes a viernes con cita previa; sábados, consultar.'),
])
AMAYA_LD = {'@context': 'https://schema.org', '@type': 'Person', 'name': 'Amaya Arnaiz Carro', 'jobTitle': 'Fisioterapeuta y osteópata',
            'url': AM, 'sameAs': [AM, 'https://www.instagram.com/amaya.arnaizz/', 'https://www.linkedin.com/in/amaya-arnaiz-carro/'],
            'telephone': '+34 606 926 228', 'email': AM_MAIL, 'worksFor': {'@id': BASE + '#centro'},
            'alumniOf': {'@type': 'CollegeOrUniversity', 'name': 'Universidad de Salamanca'},
            'knowsAbout': ['Fisioterapia', 'Osteopatía', 'Terapia fascial', 'Dolor persistente', 'Reeducación postural', 'Fisioterapia para músicos', 'Punción seca', 'Articulación temporomandibular', 'Yoga']}
PAGS['fisioterapia.html'] = ('Fisioterapeuta y osteópata en Valladolid · Amaya Arnaiz · Centro Nuha',
    'Fisioterapia y osteopatía en el centro de Valladolid con Amaya Arnaiz: dolor de espalda, dolor persistente, reeducación postural, ATM y bruxismo, osteopatía visceral, fisioterapia para músicos. C/ Gregorio Fernández 4.',
    hero('mbr-1920x1280.jpg', 'Fisioterapia · Osteopatía integrativa', 'Fisioterapia y osteopatía en Valladolid',
         'Un tratamiento integral que mira más allá del dolor. Entender y atender tu cuerpo es fundamental para recuperar tu salud.', 'Fisioterapia', botones=False) +
    f"""<section class="blk"><div class="wrap grid2">
<div class="prose"><div class="eyebrow">Amaya Arnaiz Carro</div><h2>Tu fisioterapeuta y osteópata en el centro de Valladolid</h2>
<p>La <strong>fisioterapia en Centro Nuha</strong> la lleva <strong>Amaya Arnaiz</strong>, fisioterapeuta desde 2010 y osteópata, con experiencia en la sanidad pública y privada en Madrid, Salamanca, Oviedo, León, Gijón y Valladolid. Combina técnicas manuales, ejercicio y pautas personalizadas, con un enfoque que entiende el cuerpo como una unidad para atender los problemas desde su origen.</p>
<a class="btn" href="tel:{AM_TEL_LINK}">Llamar al {AM_TEL}</a><a class="btn sage" href="{AM_WA}" target="_blank" rel="noopener">WhatsApp</a>
<p class="note">De lunes a viernes con cita previa · sábados, consultar. Más información en <a href="{AM}" target="_blank" rel="noopener">amayaarnaiz.es</a>.</p></div>
<div class="pic"><img src="{IMG}mbr-1-600x400.jpg" alt="Fisioterapia y osteopatía con Amaya Arnaiz en Valladolid" loading="lazy"></div>
</div></section>
<section class="blk alt"><div class="wrap"><div class="eyebrow">Especialidades</div><h2>Qué tratamos en la consulta de fisioterapia</h2>
<div class="tiles">
<div class="tile"><h3>Aparato locomotor</h3><p>Alteraciones posturales, dolor de espalda, hernias y protrusiones, lumbociáticas y dolores articulares.</p></div>
<div class="tile"><h3>Lesiones de tejidos blandos</h3><p>Esguinces, tendinopatías, fascitis, contracturas, puntos gatillo, sobrecargas y cicatrices.</p></div>
<div class="tile"><h3>Cráneo y mandíbula (ATM)</h3><p>Cefaleas y jaquecas, bruxismo, dolor de mandíbula y secuelas de latigazo cervical.</p></div>
<div class="tile"><h3>Osteopatía visceral</h3><p>Digestiones pesadas, hernia de hiato, reflujo, estreñimiento y secuelas de procesos inflamatorios o quirúrgicos.</p></div>
<div class="tile"><h3>Dolor persistente</h3><p>Dolor crónico desde un enfoque biopsicosocial: terapia manual, consciencia corporal y entender cómo funciona el dolor.</p></div>
<div class="tile"><h3>Reeducación postural</h3><p>Ejercicio terapéutico y educación del movimiento para que los cambios se mantengan.</p></div>
<div class="tile"><h3>Fisioterapia para músicos</h3><p>Tocar con dolor no es normal: prevención y tratamiento de lesiones del instrumentista y del cantante, y ansiedad escénica.</p></div>
<div class="tile"><h3>Rehabilitación</h3><p>Recuperación tras cirugía o inmovilización, prótesis y fracturas.</p></div>
</div></div></section>
<section class="blk"><div class="wrap grid2">
<div><div class="eyebrow">Cómo es la consulta</div><h2>Primero entender, después tratar</h2>
<ul class="list">
<li><b>Primera consulta (unos 60 min).</b> Historia clínica, exploración física y objetivos claros contigo.</li>
<li><b>Fase 1 · Alivio.</b> Técnicas manuales y ejercicios para reducir el dolor y recuperar movilidad.</li>
<li><b>Fase 2 · Consolidación (sesiones de unos 50 min).</b> Pautas y movimiento para afianzar los cambios y evitar recaídas.</li>
</ul></div>
<div><div class="eyebrow">Formación</div><h2>Formación de Amaya Arnaiz</h2>
<ul class="list">
<li><b>Diplomatura en Fisioterapia</b> · Universidad de Salamanca (2010).</li>
<li><b>C.O. en Osteopatía</b> · Escuela de Osteopatía de Madrid.</li>
<li><b>Experta en Terapia Fascial</b> · Escuela de Terapia Fascial de Madrid.</li>
<li><b>Certificado de profesionalidad en Yoga.</b></li>
<li>Formación en punción seca, ATM, ejercicio terapéutico, vendaje neuromuscular, educación somática y mindfulness aplicado a la salud.</li>
</ul></div>
</div></section>
<section class="blk alt"><div class="wrap"><div class="eyebrow">Dudas</div><h2>Preguntas sobre fisioterapia</h2>{f_html}
<p class="note" style="margin-top:18px">Si tu problema tiene también un componente digestivo o de alimentación, puedes combinarlo con la <a href="nutricion.html">consulta de nutrición</a>; para migrañas o ansiedad, con <a href="acupuntura.html">acupuntura</a>.</p></div></section>
<section class="blk"><div class="wrap"><div class="band"><div><h2>Pide cita de fisioterapia</h2><p>Amaya Arnaiz · lunes a viernes con cita previa.</p></div><div><a class="btn" href="tel:{AM_TEL_LINK}">{AM_TEL}</a><a class="btn ghost" href="{AM_WA}" target="_blank" rel="noopener">WhatsApp</a></div></div></div></section>
""", [f_ld, AMAYA_LD, migas(('Inicio', ''), ('Fisioterapia', 'fisioterapia.html'))])

# ------------------------------------------------------------------ acupuntura
f_html, f_ld = faq([
    ('¿Quién realiza la acupuntura?', 'Adolfo Argüello Fernández, médico acupuntor colegiado en Valladolid (nº 474706370): licenciado en Medicina y Cirugía por la Universidad de Valladolid y diplomado en Medicina Tradicional China por la escuela Tian y la escuela de Alfredo Embid.'),
    ('¿Para qué se usa la acupuntura?', 'En la consulta los motivos más frecuentes son lumbociáticas y dolor de espalda, migrañas y cefaleas, ansiedad y estrés, y el acompañamiento en el embarazo (fertilidad, malposiciones fetales y náuseas).'),
    ('¿La acupuntura duele?', 'Las agujas son muy finas. Lo habitual es notar un pinchazo leve o una sensación de pesadez u hormigueo en la zona, que desaparece en poco tiempo.'),
    ('¿Cómo pido cita de acupuntura en Valladolid?', f'Llama al {TEL} o escribe a {EMAIL}. Estamos en {DIR}, en el centro de Valladolid.'),
])
PAGS['acupuntura.html'] = ('Acupuntura en Valladolid · Médico acupuntor Adolfo Argüello · Centro Nuha',
    'Acupuntura en el centro de Valladolid con el médico acupuntor Adolfo Argüello Fernández: dolor de espalda y lumbociática, migrañas, ansiedad y estrés, fertilidad y embarazo. Centro Nuha, C/ Gregorio Fernández 4.',
    hero('mbr-2000x1651.jpg', 'Acupuntura · Medicina tradicional china', 'Acupuntura en Valladolid con médico acupuntor',
         'La acupuntura es un componente clave de la medicina tradicional china, junto con la fitoterapia, la moxibustión y el tui-na.', 'Acupuntura') +
    f'''<section class="blk"><div class="wrap grid2">
<div class="prose"><div class="eyebrow">Adolfo Argüello Fernández</div><h2>Un médico que también es acupuntor</h2>
<p>En Centro Nuha la <strong>acupuntura en Valladolid</strong> la realiza <strong>Adolfo Argüello Fernández</strong>, médico licenciado en Medicina y Cirugía por la Universidad de Valladolid y diplomado en Medicina Tradicional China por la escuela Tian y la escuela de Alfredo Embid, colegiado en el Colegio de Médicos de Valladolid con el nº 474706370. Une la valoración médica con la visión de la medicina oriental, que se usa tanto en la prevención como en el tratamiento.</p></div>
<div class="pic"><img src="{IMG}mbr-1-2000x1125.jpg" alt="Consulta de acupuntura en Valladolid" loading="lazy"></div>
</div></section>
<section class="blk alt"><div class="wrap"><div class="eyebrow">Motivos de consulta</div><h2>Lo que más tratamos con acupuntura</h2>
<div class="tiles">
<div class="tile"><h3>Lumbociáticas y dolor de espalda</h3><p>Uno de los problemas más frecuentes de nuestra sociedad.</p></div>
<div class="tile"><h3>Migrañas y cefaleas</h3><p>Dolores muy crónicos que con frecuencia se alivian con acupuntura.</p></div>
<div class="tile"><h3>Ansiedad y estrés</h3><p>La acupuntura ayuda a disminuir los signos y síntomas propios de la ansiedad.</p></div>
<div class="tile"><h3>Fertilidad y embarazo</h3><p>Apoyo en problemas de fertilidad, malposiciones fetales y náuseas del embarazo.</p></div>
</div>
<p class="note" style="margin-top:22px">Si además tienes dolor muscular o articular, la acupuntura se puede complementar con <a href="fisioterapia.html">fisioterapia y osteopatía</a> en el mismo centro.</p>
</div></section>
<section class="blk"><div class="wrap"><div class="eyebrow">Dudas</div><h2>Preguntas sobre acupuntura</h2>{f_html}</div></section>
''' + BANDA, [f_ld, {'@context': 'https://schema.org', '@type': 'Person', 'name': 'Adolfo Argüello Fernández', 'jobTitle': 'Médico acupuntor', 'identifier': {'@type': 'PropertyValue', 'propertyID': 'Nº de colegiado (Colegio Oficial de Médicos de Valladolid)', 'value': '474706370'}, 'worksFor': {'@id': BASE + '#centro'}, 'alumniOf': {'@type': 'CollegeOrUniversity', 'name': 'Universidad de Valladolid'}, 'knowsAbout': ['Acupuntura', 'Medicina tradicional china', 'Medicina']}, migas(('Inicio', ''), ('Acupuntura', 'acupuntura.html'))])

# ------------------------------------------------------------------ nutricion
f_html, f_ld = faq([
    ('¿Quién lleva la consulta de nutrición?', 'Andrea Argüello Arbe, dietista-nutricionista colegiada (CYL00060), diplomada en Nutrición y Dietética por la Universidad de Valladolid, con Máster en Metabolismo por la Universidad de Barcelona y Máster en Nutrición Oncológica.'),
    ('¿Puedo hacer la consulta online?', 'Sí. Si no puedes venir al centro o vives fuera de Valladolid, el seguimiento por videollamada es igual de efectivo.'),
    ('¿Cómo reservo cita de nutrición?', f'Puedes pedirla con el formulario de nutriendohabitos.es, llamar o escribir por WhatsApp al 669 87 70 52.'),
])
PAGS['nutricion.html'] = ('Nutricionista en Valladolid · Consulta de nutrición en Centro Nuha',
    'Consulta de nutrición en Centro Nuha (Valladolid) con Andrea Argüello, dietista-nutricionista de Nutriendo Hábitos: hábitos saludables sin dietas estrictas, nutrición deportiva, oncológica y digestiva. Presencial y online.',
    hero('mbr-2000x1125.jpg', 'Nutrición · Nutriendo Hábitos', 'Nutricionista en Valladolid, en Centro Nuha',
         'Aprender a incorporar de forma sencilla buenos hábitos en tu día a día. ¡Basta de dietas estrictas y de pesar alimentos!', 'Nutrición', botones=False) +
    f'''<section class="blk"><div class="wrap grid2">
<div class="prose"><div class="eyebrow">Nutriendo Hábitos</div><h2>La consulta de nutrición del centro</h2>
<p>La nutrición de Centro Nuha la lleva <strong>Andrea Argüello Arbe</strong>, dietista-nutricionista colegiada desde 2013 y responsable de <a href="{NH}">Nutriendo Hábitos</a>. Con ella no te impondremos dietas: aprenderás a comer.</p>
<p>Atiende pérdida y ganancia de peso, <a href="{NH}nutricion-deportiva-valladolid.html">nutrición deportiva</a>, <a href="{NH}nutricion-oncologica-valladolid.html">nutrición oncológica</a>, <a href="{NH}patologias-digestivas-valladolid.html">patologías digestivas (SIBO, colon irritable, intolerancias)</a> y alimentación vegetariana y vegana.</p>
<a class="btn sage" href="{NH}contacto.html#formulario">Pedir cita de nutrición</a><a class="btn ghost" style="color:var(--sage-d)" href="{NH}">Ver Nutriendo Hábitos</a></div>
<div class="pic"><img src="{IMG}mbr-6-2000x1333.jpg" alt="Consulta de nutrición en Valladolid" loading="lazy"></div>
</div></section>
<section class="blk alt"><div class="wrap"><div class="eyebrow">Qué aprenderás</div><h2>Con nosotros aprenderás a comer</h2>
<div class="tiles">
<div class="tile"><h3>Aprender a comprar</h3><p>Hacer mejor la compra, leer los ingredientes y saber lo que compras.</p></div>
<div class="tile"><h3>Ahorrar dinero</h3><p>Sin suplementos milagro ni productos caros.</p></div>
<div class="tile"><h3>Conseguir tus objetivos</h3><p>Verás los resultados día a día, con seguimiento.</p></div>
<div class="tile"><h3>Mejorar tu salud</h3><p>Hábitos que se mantienen en el tiempo.</p></div>
</div>
<p class="note" style="margin-top:22px"><strong>Seguimiento a distancia:</strong> si no puedes acudir a la consulta o vives fuera de Valladolid, también puedes hacer la <a href="{NH}nutricionista-online.html">consulta de nutrición online</a>.</p>
</div></section>
<section class="blk"><div class="wrap"><div class="eyebrow">Dudas</div><h2>Preguntas sobre nutrición</h2>{f_html}</div></section>
''', [f_ld, migas(('Inicio', ''), ('Nutrición', 'nutricion.html'))])

# ------------------------------------------------------------------ psicologia
# Profesional aún sin confirmar: cuando lo haya, añadir nombre, titulación y n.º de colegiado/a (no inventar).
f_html, f_ld = faq([
    ('¿Qué se trata en la consulta de psicología?', 'Ansiedad y estrés, estado de ánimo bajo, autoestima, duelo, momentos de cambio o crisis, dificultades en las relaciones y malestar emocional que se refleja en el cuerpo, como el dolor persistente o la relación con la comida.'),
    ('¿Cómo es la primera consulta?', 'Es una conversación para conocer qué te trae, cómo te afecta en tu día a día y qué te gustaría cambiar. A partir de ahí se plantea un plan de trabajo adaptado a ti, con objetivos realistas.'),
    ('¿Se coordina con el resto de profesionales del centro?', 'Sí. Cuando lo necesitas y con tu permiso, la psicología se coordina con fisioterapia, acupuntura o nutrición, por ejemplo en dolor crónico, ansiedad con síntomas físicos o problemas con la alimentación.'),
    ('¿Cómo pido cita de psicología en Valladolid?', f'Llama al {TEL} o escribe a {EMAIL} y te informamos de la disponibilidad.'),
])
PAGS['psicologia.html'] = ('Psicología en Valladolid · Consulta de psicología en Centro Nuha',
    f'Consulta de psicología en Centro Nuha, en el centro de Valladolid ({DIR}): ansiedad y estrés, estado de ánimo, autoestima, duelo y momentos de cambio. Pide información en el {TEL}.',
    hero('mbr-2000x1052.jpg', 'Psicología · Bienestar emocional', 'Psicología en Valladolid, en Centro Nuha',
         'Cuidar la mente también es cuidar la salud. Un espacio cercano y confidencial para entender lo que te pasa y encontrar tus propios recursos.', 'Psicología') +
    f'''<section class="blk"><div class="wrap grid2">
<div class="prose"><div class="eyebrow">Nueva especialidad</div><h2>La consulta de psicología del centro</h2>
<p>Centro Nuha incorpora la <strong>psicología</strong> a su equipo para atender a la persona de forma completa: muchas veces el cuerpo y la mente van de la mano, y un mismo problema mejora antes cuando se aborda desde varios enfoques.</p>
<p>La terapia es un trabajo conjunto entre paciente y profesional. Partimos de una buena relación de confianza, escuchamos sin juzgar y avanzamos paso a paso hacia objetivos realistas.</p>
<p class="note">Muy pronto presentaremos aquí al profesional de psicología del centro. Mientras tanto, puedes llamarnos y te informamos.</p>
<a class="btn" href="tel:{TEL_LINK}">Llamar al {TEL}</a></div>
<div class="pic"><img src="{IMG}mbr-2000x1333.jpg" alt="Acompañamiento psicológico en Centro Nuha, Valladolid" loading="lazy"></div>
</div></section>
<section class="blk alt"><div class="wrap"><div class="eyebrow">En qué te podemos ayudar</div><h2>Motivos frecuentes de consulta</h2>
<div class="tiles">
<div class="tile"><h3>Ansiedad y estrés</h3><p>Nervios, preocupación constante, insomnio o sensación de no llegar a todo.</p></div>
<div class="tile"><h3>Estado de ánimo</h3><p>Tristeza, desánimo, falta de ilusión o de energía.</p></div>
<div class="tile"><h3>Autoestima y relaciones</h3><p>Inseguridad, dificultades con la pareja, la familia o el trabajo.</p></div>
<div class="tile"><h3>Momentos de cambio</h3><p>Duelos, separaciones, cambios vitales o situaciones que te desbordan.</p></div>
</div>
<p class="note" style="margin-top:22px"><strong>Cuerpo y mente:</strong> si además tienes <a href="fisioterapia.html">dolor persistente</a>, síntomas físicos de ansiedad o una relación difícil con la comida (<a href="nutricion.html">nutrición</a>), en Centro Nuha los profesionales pueden coordinarse contigo.</p>
</div></section>
<section class="blk"><div class="wrap"><div class="eyebrow">Dudas</div><h2>Preguntas sobre psicología</h2>{f_html}</div></section>
''' + BANDA, [f_ld, migas(('Inicio', ''), ('Psicología', 'psicologia.html'))])

# ------------------------------------------------------------------ quienes somos
PAGS['quienes-somos.html'] = ('Quiénes somos · Profesionales de Centro Nuha, Valladolid',
    'Conoce al equipo de Centro Nuha en Valladolid: Adolfo Argüello (médico acupuntor), Amaya Arnaiz (fisioterapeuta y osteópata) y Andrea Argüello (dietista-nutricionista). Más de 10 años cuidando la salud en el centro de la ciudad.',
    hero('mbr-1626x1080.jpg', 'Quiénes somos', 'Los profesionales de Centro Nuha',
         'Muchos años nos avalan. Cada especialidad la lleva un profesional sanitario con formación específica.', 'Quiénes somos') +
    f'''<section class="blk"><div class="wrap"><div class="team">
<article class="pro"><div class="role">Médico acupuntor</div><h3>Adolfo Argüello Fernández</h3><ul><li>Licenciado en Medicina y Cirugía por la Universidad de Valladolid.</li><li>Colegiado nº 474706370 · Colegio de Médicos de Valladolid.</li><li>Diplomado en Medicina Tradicional China por la escuela Tian y la escuela de Alfredo Embid.</li></ul><p><a href="acupuntura.html">Acupuntura en Valladolid →</a></p></article>
<article class="pro"><div class="role">Fisioterapeuta y osteópata</div><h3>Amaya Arnaiz Carro</h3><ul><li>Diplomada en Fisioterapia por la Universidad de Salamanca (2010).</li><li>C.O. en Osteopatía por la Escuela de Osteopatía de Madrid.</li><li>Experta en Terapia Fascial.</li><li>Certificado de profesionalidad en Yoga.</li><li>Especialista en dolor persistente y fisioterapia para músicos · <a href="https://amayaarnaiz.es/" target="_blank" rel="noopener">amayaarnaiz.es</a></li></ul><p><a href="fisioterapia.html">Fisioterapia en Valladolid →</a></p></article>
<article class="pro"><div class="role">Dietista-nutricionista</div><h3>Andrea Argüello Arbe</h3><ul><li>Diplomada en Nutrición y Dietética por la Universidad de Valladolid.</li><li>Máster en Metabolismo por la Universidad de Barcelona.</li><li>Máster en Nutrición Oncológica (2026).</li><li>Colegiada nº CYL00060.</li></ul><p><a href="nutricion.html">Nutrición en Valladolid →</a></p></article>
<article class="pro"><div class="role">Psicología</div><h3>Próximamente</h3><ul><li>Muy pronto presentaremos al profesional de psicología del centro.</li><li>Mientras tanto, te informamos en el {TEL}.</li></ul><p><a href="psicologia.html">Psicología en Valladolid →</a></p></article>
</div></div></section>
''' + BANDA, [migas(('Inicio', ''), ('Quiénes somos', 'quienes-somos.html'))])

# ------------------------------------------------------------------ instalaciones
PAGS['instalaciones.html'] = ('Instalaciones · Centro Nuha, en el centro de Valladolid',
    f'Centro Nuha está en {DIR}, en el centro de Valladolid y a pocos minutos del Campo Grande. Instalaciones amplias y adaptadas a todos los pacientes.',
    hero('48418577-1970798716555404-7240199208941125632-n-960x960.jpg', 'Instalaciones', 'Un centro amplio en pleno centro de Valladolid',
         f'{DIR}, a pocos minutos andando del Campo Grande.', 'Instalaciones') +
    f'''<section class="blk"><div class="wrap grid2">
<div class="prose"><h2>Pensado para que estés a gusto</h2><p>Unas instalaciones amplias y adaptadas a todos nuestros pacientes, con salas independientes para fisioterapia, acupuntura y nutrición.</p>
<p><strong>Dirección:</strong> {DIR}, {CP} Valladolid.<br><a href="{MAPS}" target="_blank" rel="noopener">Ver en Google Maps</a></p></div>
<div class="pic"><img src="{IMG}mbr-1920x1280.jpg" alt="Sala de tratamiento de Centro Nuha en Valladolid" loading="lazy"></div>
</div></section>
''' + BANDA, [migas(('Inicio', ''), ('Instalaciones', 'instalaciones.html'))])

# ------------------------------------------------------------------ contacto
PAGS['contacto.html'] = ('Contacto y cita · Centro Nuha, Valladolid',
    f'Pide cita en Centro Nuha (Valladolid), {DIR}: fisioterapia con Amaya Arnaiz (606 926 228), nutrición con Andrea Argüello (669 87 70 52) y acupuntura ({TEL}).',
    hero('mbr-1626x1080.jpg', 'Contacto', 'Pide cita en Centro Nuha', 'Llámanos o escríbenos y te contamos cómo podemos ayudarte.', 'Contacto', botones=False) +
    f'''<section class="blk"><div class="wrap">
<h2>Cita con cada profesional</h2>
<div class="contact">
<a class="c" href="https://wa.me/34606926228" target="_blank" rel="noopener"><b>Fisioterapia · Amaya Arnaiz</b><span>606 926 228</span><br><small>Teléfono y WhatsApp · contacto@amayaarnaiz.es</small></a>
<a class="c" href="{NH}contacto.html#formulario"><b>Nutrición · Andrea Argüello</b><span>669 87 70 52</span><br><small>Teléfono, WhatsApp y formulario</small></a>
<a class="c" href="tel:{TEL_LINK}"><b>Psicología</b><span>{TEL}</span><br><small>Te informamos de la disponibilidad</small></a>
<a class="c" href="tel:{TEL_LINK}"><b>Acupuntura y centro</b><span>{TEL}</span><br><small>Teléfono o formulario</small></a>
</div>
<h2 id="formulario" style="margin-top:44px">Escríbenos</h2>
<p class="note">Déjanos tu mensaje y te respondemos lo antes posible, normalmente en uno o dos días laborables.</p>
<form class="form" id="f-contacto" data-url="{html.escape(FORM_URL)}" novalidate>
<div class="fr"><label>Nombre *<input name="nombre" required autocomplete="name" maxlength="80"></label><label>Teléfono *<input name="telefono" type="tel" required autocomplete="tel" maxlength="20"></label></div>
<div class="fr"><label>Correo electrónico<input name="email" type="email" autocomplete="email" maxlength="120"></label><label>Especialidad<select name="especialidad"><option>Fisioterapia y osteopatía</option><option>Acupuntura</option><option>Nutrición</option><option>Psicología</option><option>Talleres</option><option selected>Otra consulta</option></select></label></div>
<label>Mensaje *<textarea name="mensaje" rows="5" required maxlength="2000" placeholder="Cuéntanos brevemente en qué te podemos ayudar y cuándo te viene bien que te llamemos."></textarea></label>
<label class="hp" aria-hidden="true">No rellenar<input name="web" tabindex="-1" autocomplete="off"></label>
<label class="chk"><input type="checkbox" name="acepto" required><span>He leído la <a href="proteccion-de-datos.html">política de protección de datos</a> y acepto que usen mis datos para responder a mi consulta.</span></label>
<button class="btn" type="submit">Enviar mensaje</button>
<p class="fmsg" id="f-msg" role="status" aria-live="polite"></p>
</form>
<h2 style="margin-top:40px">Dónde estamos</h2>
<div class="contact">
<a class="c" href="{MAPS}" target="_blank" rel="noopener"><b>Dirección</b><span>{DIR} (local)</span><br><small>{CP} Valladolid · a pocos minutos del Campo Grande</small></a>
</div>
</div></section>
''', [migas(('Inicio', ''), ('Contacto', 'contacto.html'))])

# ------------------------------------------------------------------ talleres
PAGS['cursos-talleres.html'] = ('Talleres y clases de salud · Centro Nuha, Valladolid',
    'Talleres de nutrición y cocina saludable y clases de movimiento y yoga con fisioterapeuta en Centro Nuha (Valladolid). Te avisamos de los próximos.',
    hero('28516062-353583385141636-3635879251579606501-o-2-1000x666.jpg', 'Cursos y talleres', 'Talleres de salud en Valladolid',
         'Actualmente no hay talleres abiertos. Si quieres que te avisemos de los próximos, escríbenos.', 'Talleres', botones=False) +
    f'''<section class="blk"><div class="wrap"><div class="eyebrow">Talleres realizados</div><h2>Algunos talleres que hemos hecho</h2>
<div class="grid3">
<article class="card"><img src="{IMG}48418577-1970798716555404-7240199208941125632-n-960x960.jpg" alt="Taller de tuppers saludables" loading="lazy"><div class="in"><h3>Comida para llevar al trabajo</h3><p>Opciones sanas y originales para comer de táper sin caer en la rutina. Con degustación.</p></div></article>
<article class="card"><img src="{IMG}51251581-1995473034087972-8937401444860952576-n-800x800.jpg" alt="Taller de desayunos y meriendas" loading="lazy"><div class="in"><h3>Desayunos y meriendas originales</h3><p>Patés vegetales, pan de masa madre y otras ideas para desayunar y merendar mejor. Con degustación.</p></div></article>
<article class="card"><img src="{IMG}27628767-1790940321207912-5785969058236033741-o-1-794x1123.jpg" alt="Taller de hambre emocional" loading="lazy"><div class="in"><h3>El hambre emocional</h3><p>Entender esas ganas compulsivas de comer dulce o picar, y cómo gestionarlas.</p></div></article>
</div>
<h2 style="margin-top:48px">Clases de movimiento con Amaya Arnaiz</h2>
<div class="tiles"><div class="tile"><h3>Salud a través del movimiento</h3><p>Clases de yoga y movimiento consciente con fisioterapeuta.</p></div><div class="tile"><h3>Salud y música</h3><p>Terapia y educación corporal para músicos y cantantes.</div><div class="tile"><h3>Educación cuerpo-mente</h3><p>Aprender a escuchar el cuerpo para cuidar tu salud.</p></div></div>
<p class="note">Horarios e inscripción en <a href="https://amayaarnaiz.es/clases-y-talleres/" target="_blank" rel="noopener">amayaarnaiz.es</a> o en el 606 926 228.</p>
<p class="note" style="margin-top:22px">¿Te interesa un taller? <a href="contacto.html#formulario">Escríbenos</a> y te avisamos.</p>
</div></section>''', [migas(('Inicio', ''), ('Talleres', 'cursos-talleres.html'))])

# ------------------------------------------------------------------ protección de datos
PAGS['proteccion-de-datos.html'] = ('Protección de datos · Centro Nuha',
    'Información sobre el tratamiento de datos personales en la web de Centro Nuha (Valladolid).',
    f'''<section class="blk"><div class="wrap prose"><h1>Protección de datos</h1>
<h2>1. Responsable</h2><p>Identidad: Adolfo Argüello Fernández (Centro Nuha).<br>Dirección postal: {DIR}, {CP} Valladolid.<br>Teléfono: {TEL}. Correo electrónico: {EMAIL}.</p>
<h2>2. Finalidad</h2><p>Los datos que nos facilites por teléfono, correo o el formulario de contacto (nombre, teléfono, correo y mensaje) se usan para atender tus consultas, gestionar las citas y prestarte el servicio sanitario solicitado. Se conservan mientras sean necesarios para ello y, después, durante los plazos que exige la ley (en el caso de la historia clínica, los que marca la normativa sanitaria).</p>
<h2>3. Legitimación</h2><p>Tu consentimiento al contactar con nosotros y la relación asistencial cuando pides cita.</p>
<h2>4. Destinatarios</h2><p>No cedemos tus datos a terceros salvo obligación legal. Los mensajes del formulario llegan al correo del centro a través de Google (Gmail), que actúa como proveedor del servicio. La consulta de nutrición la gestiona Nutriendo Hábitos, que tiene su propia <a href="{NH}proteccion-de-datos.html">política de privacidad</a>.</p>
<h2>5. Derechos</h2><p>Puedes acceder, rectificar o suprimir tus datos, oponerte o limitar su tratamiento y pedir su portabilidad escribiendo a {EMAIL}. Si crees que no se han respetado, puedes reclamar ante la Agencia Española de Protección de Datos (aepd.es).</p>
<h2>6. Esta web</h2><p>Esta web no usa cookies de publicidad ni de analítica. Solo carga las fuentes tipográficas de Google Fonts.</p>
</div></section>''')

# ------------------------------------------------------------------ salida
if OUT.exists():
    shutil.rmtree(OUT)
OUT.mkdir()
if MODO != 'preview':
    shutil.copytree(Path(__file__).parent / 'imagenes', OUT / 'assets' / 'images')
EXTRA = {}
for archivo, (t, d, cuerpo, *resto) in PAGS.items():
    ld = resto[0] if resto and isinstance(resto[0], list) else None
    rob = 'noindex,follow' if archivo == 'proteccion-de-datos.html' else 'index,follow'
    (OUT / archivo).write_text(pagina(archivo, t, d, cuerpo, ld, rob), encoding='utf-8')

E404 = f"""<section class="e404"><div class="wrap">
<img class="enso" src="{IMG}logo-265x244.png" alt="" width="132" height="122">
<div class="eyebrow">Error 404 · Página no encontrada</div>
<h1>La página que buscas no está disponible</h1>
<p class="lead">Es posible que la dirección haya cambiado o que la página ya no exista. Le invitamos a continuar desde alguna de nuestras especialidades o a contactar con el centro.</p>
<div class="e404-btns"><a class="btn" href="index.html">Ir a la página de inicio</a><a class="btn ghost" href="contacto.html">Contactar con el centro</a></div>
<div class="e404-grid">
<a href="fisioterapia.html"><b>Fisioterapia y osteopatía</b><span>Amaya Arnaiz · 606 926 228</span></a>
<a href="acupuntura.html"><b>Acupuntura</b><span>Adolfo Argüello · {TEL}</span></a>
<a href="nutricion.html"><b>Nutrición</b><span>Andrea Argüello · 669 87 70 52</span></a>
</div>
<p class="e404-dir">Centro Nuha · {DIR}, {CP} Valladolid</p>
</div></section>
<style>
.e404{{padding:90px 0 100px;text-align:center;background:radial-gradient(circle at 50% 0,#fff 0,var(--sand) 60%)}}
.e404 .enso{{margin:0 auto 26px;width:132px;height:auto;opacity:.9}}
.e404 h1{{max-width:22ch;margin:.25em auto .4em}}
.e404 .lead{{max-width:38rem;margin:0 auto 1.6em;color:var(--ink2);font-size:1.1rem}}
.e404 .btn.ghost{{color:var(--sage-d)}}
.e404-grid{{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;max-width:860px;margin:54px auto 0;text-align:left}}
.e404-grid a{{display:block;background:#fff;border:1px solid var(--line);border-radius:16px;padding:20px 22px;text-decoration:none;color:var(--ink);transition:border-color .2s,transform .2s}}
.e404-grid a:hover{{border-color:var(--sage);transform:translateY(-2px)}}
.e404-grid b{{display:block;font:700 1rem 'Montserrat',sans-serif;margin-bottom:4px}}
.e404-grid span{{color:var(--ink2);font-size:.92rem}}
.e404-dir{{margin-top:40px;font:600 .8rem 'Montserrat',sans-serif;letter-spacing:.12em;text-transform:uppercase;color:var(--ink2)}}
@media(max-width:760px){{.e404{{padding:60px 0 70px}}.e404-grid{{grid-template-columns:1fr}}}}
</style>"""
import re as _re
_404 = pagina('404.html', 'Página no encontrada · Centro Nuha', 'La página que buscas no está disponible.', E404, None, 'noindex,follow')
_404 = _re.sub(r'href="([a-z0-9-]+\.html)"', r'href="/\1"', _404).replace('src="assets', 'src="/assets').replace("url('assets", "url('/assets")
(OUT / '404.html').write_text(_404, encoding='utf-8')

indexables = [a for a in PAGS if a != 'proteccion-de-datos.html']
(OUT / 'sitemap.xml').write_text('<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n' + ''.join(
    f'  <url><loc>{BASE}{"" if a == "index.html" else a}</loc><lastmod>{HOY}</lastmod></url>\n' for a in indexables) + '</urlset>\n', encoding='utf-8')
(OUT / 'robots.txt').write_text(f'User-agent: *\nAllow: /\n\nSitemap: {BASE}sitemap.xml\n', encoding='utf-8')
(OUT / 'llms.txt').write_text(f"""# Centro Nuha (NUHA – Salud Integrativa) · Fisioterapia, acupuntura, nutrición y psicología en Valladolid

> Centro de salud en {DIR} ({CP} Valladolid), a pocos minutos del Campo Grande. Teléfono {TEL} · {EMAIL}. Antes se llamaba Centro Los Arces.

- [Fisioterapia y osteopatía en Valladolid]({BASE}fisioterapia.html): Amaya Arnaiz Carro, fisioterapeuta (Universidad de Salamanca, 2010) y osteópata (Escuela de Osteopatía de Madrid), experta en terapia fascial. Dolor persistente, reeducación postural, fisioterapia para músicos, espalda, ATM, osteopatía visceral. 606 926 228 · https://amayaarnaiz.es/
- [Acupuntura en Valladolid]({BASE}acupuntura.html): Adolfo Argüello Fernández, médico acupuntor colegiado nº 474706370 en Valladolid (Medicina y Cirugía, Universidad de Valladolid; Medicina Tradicional China). Lumbociáticas, migrañas, ansiedad, fertilidad y embarazo.
- [Nutrición en Valladolid]({BASE}nutricion.html): Andrea Argüello Arbe, dietista-nutricionista colegiada CYL00060 (Nutriendo Hábitos, {NH}). Presencial y online.
- [Psicología en Valladolid]({BASE}psicologia.html): consulta de psicología (ansiedad, estrés, estado de ánimo, autoestima, duelo), coordinada con el resto del equipo. Información en el {TEL}.
- [Quiénes somos]({BASE}quienes-somos.html) · [Contacto]({BASE}contacto.html)
""", encoding='utf-8')
if MODO == 'web':
    (OUT / '.htaccess').write_text(f"""DirectoryIndex index.html
ErrorDocument 404 /404.html

<IfModule mod_rewrite.c>
  RewriteEngine On
  # siempre https://www.centronuha.es
  RewriteCond %{{HTTPS}} off [OR]
  RewriteCond %{{HTTP_HOST}} ^centronuha\\.es$ [NC]
  RewriteRule ^ https://www.centronuha.es%{{REQUEST_URI}} [L,R=301]
  # páginas antiguas
  RewriteRule ^nuestras\\.instalaciones\\.html$ /instalaciones.html [L,R=301]
  # direcciones cortas: /acupuntura -> /acupuntura.html (sirve para acupunturavalladolid.com)
  RewriteRule ^(acupuntura|fisioterapia|nutricion|psicologia|quienes-somos|instalaciones|contacto|cursos-talleres)/?$ /$1.html [L,R=301,NC]
</IfModule>

<IfModule mod_deflate.c>
  AddOutputFilterByType DEFLATE text/html text/css application/javascript text/plain application/xml
</IfModule>
<IfModule mod_expires.c>
  ExpiresActive On
  ExpiresByType image/jpeg "access plus 1 month"
  ExpiresByType image/png "access plus 1 month"
</IfModule>
""", encoding='utf-8')
print('OK', OUT, len(list(OUT.iterdir())), 'archivos')
