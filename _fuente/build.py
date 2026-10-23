#!/usr/bin/env python3
"""
Genera la web publicable por páginas (una URL por sección, buena para Google)
a partir de nutriendo-habitos.html (la versión de una sola página de la vista previa).

  python3 build.py prueba      -> build/prueba      (para /nueva/, con noindex)
  python3 build.py definitiva  -> build/definitiva  (para la raíz del dominio, con sitemap)
"""
import html, json, re, shutil, sys, datetime
from pathlib import Path

MODO = sys.argv[1] if len(sys.argv) > 1 else 'definitiva'
BASE = 'https://www.nutriendohabitos.es/' if MODO == 'definitiva' else 'https://www.nutriendohabitos.es/nueva/'
AQUI = Path(__file__).resolve().parent
SRC = (AQUI / 'nutriendo-habitos.html').read_text(encoding='utf-8')
SITE = AQUI / 'site'
OUT = AQUI / 'build' / MODO
HOY = datetime.date.today().isoformat()

# ---------------------------------------------------------------- páginas
# slug de data-page -> (archivo, título, descripción, prioridad sitemap)
PAGINAS = {
    'inicio': ('index.html',
        'Nutricionista en Valladolid | Andrea Argüello · Nutriendo Hábitos',
        'Dietista-nutricionista en Valladolid desde 2013. Consulta presencial y online: nutrición deportiva, nutrición oncológica, pérdida de peso y patología digestiva. Reserva tu cita.', '1.0'),
    'dietista-nutricionista-en-valladolid': ('dietista-nutricionista-en-valladolid.html',
        'Dietista-nutricionista en Valladolid · Consulta y servicios | Nutriendo Hábitos',
        'Consulta de nutrición en Valladolid con Andrea Argüello: sobrepeso, ganancia de peso, nutrición deportiva, patología digestiva, oncológica y vegana. Bioimpedancia y seguimiento.', '0.9'),
    'nutricion-deportiva-valladolid': ('nutricion-deportiva-valladolid.html',
        'Nutrición deportiva en Valladolid | Nutricionista deportiva · Nutriendo Hábitos',
        'Nutricionista deportiva en Valladolid y online: alimentación antes, durante y después del entrenamiento, escalada y composición corporal con Andrea Argüello.', '0.9'),
    'nutricion-oncologica-valladolid': ('nutricion-oncologica-valladolid.html',
        'Nutrición oncológica en Valladolid y online | Nutriendo Hábitos',
        'Nutricionista oncológica en Valladolid y online: alimentación antes, durante y después del tratamiento del cáncer, coordinada con tu equipo médico.', '0.9'),
    'nutricionista-online': ('nutricionista-online.html',
        'Nutricionista online · Consulta por videollamada | Nutriendo Hábitos',
        'Consulta de nutrición online con Andrea Argüello: dietas personalizadas y seguimiento por videollamada desde cualquier punto de España.', '0.8'),
    'nuestros-servicios': ('nuestros-servicios.html',
        'Servicios de nutrición y dietética en Valladolid | Nutriendo Hábitos',
        'Todos los servicios de la consulta: sobrepeso, ganancia de peso, TCA, educación nutricional, nutrición deportiva, alimentación infantil (BLW), patología digestiva y diabetes.', '0.7'),
    'reservar': ('reservar.html',
        'Reservar cita con nutricionista en Valladolid | Nutriendo Hábitos',
        'Reserva tu cita con la nutricionista Andrea Argüello: consulta presencial en Valladolid u online. Elige día y hora en el calendario.', '0.8'),
    'asesoria-externa': ('asesoria-externa.html',
        'Asesoría nutricional para colegios, comedores y empresas en Valladolid | Nutriendo Hábitos',
        'Asesoría nutricional externa en Valladolid para colegios, comedores escolares, residencias, empresas y madres de día.', '0.6'),
    'publicaciones': ('publicaciones.html',
        'Colaboraciones y Sputnik Investigación | Nutriendo Hábitos',
        'Colaboraciones de Nutriendo Hábitos: Sputnik Investigación, Universidad de Valladolid, Ultra de Gredos, Cruz Roja Palencia y otras entidades.', '0.5'),
    'cursos-talleres': ('cursos-talleres.html',
        'Cursos y talleres de nutrición en Valladolid y online | Nutriendo Hábitos',
        'Cursos y talleres de nutrición y cocina saludable en Valladolid y online, incluido el curso de nutrición en escalada.', '0.6'),
    'blog-nutricion': ('blog-nutricion.html',
        'Blog de nutrición: recetas y consejos | Nutriendo Hábitos',
        'Artículos, recetas e infografías de Andrea Argüello sobre alimentación, hambre emocional, bebidas isotónicas, leche sin lactosa y más.', '0.6'),
    'tips': ('tips.html',
        'Tips nutricionales | Nutriendo Hábitos',
        'Infografías y consejos rápidos de nutrición para el día a día.', '0.5'),
    'contacto': ('contacto.html',
        'Contacto · Nutricionista en Valladolid | Nutriendo Hábitos',
        'Contacta con Andrea Argüello, nutricionista en Valladolid: Calle Gregorio Fernández 4, teléfono 669 87 70 52, WhatsApp y formulario.', '0.7'),
    'proteccion-de-datos': ('proteccion-de-datos.html',
        'Protección de datos | Nutriendo Hábitos',
        'Información sobre el tratamiento de datos personales de Nutriendo Hábitos.', '0.1'),
}
ALIAS = {'servicios': 'dietista-nutricionista-en-valladolid', 'online': 'nutricionista-online', 'asesoria': 'asesoria-externa',
         'colaboraciones': 'publicaciones', 'cursos': 'cursos-talleres', 'blog': 'blog-nutricion',
         'todos-los-servicios': 'nuestros-servicios', 'nutricion-deportiva': 'nutricion-deportiva-valladolid',
         'privacidad': 'proteccion-de-datos', 'reserva': 'reservar', 'cita': 'reservar',
         'oncologica': 'nutricion-oncologica-valladolid', 'nutricion-oncologica': 'nutricion-oncologica-valladolid'}
FILES = {k: v[0] for k, v in PAGINAS.items()}
FILES_JS = {k: ('./' if v == 'index.html' else v) for k, v in FILES.items()}
for a, b in ALIAS.items():
    FILES_JS[a] = FILES_JS[b]

# anclas que viven en una página concreta
ANCLAS = {}
for m in re.finditer(r'<div data-page="([^"]+)"', SRC):
    pass
NAV = [('inicio', 'Inicio'), ('dietista-nutricionista-en-valladolid', 'Servicios'), ('nutricionista-online', 'Online'),
       ('asesoria-externa', 'Asesoría externa'), ('publicaciones', 'Colaboraciones'), ('cursos-talleres', 'Cursos/Talleres'),
       ('blog-nutricion', 'Blog'), ('contacto', 'Contacto')]
NAVMAP = {'nutricion-oncologica-valladolid': 'dietista-nutricionista-en-valladolid', 'nuestros-servicios': 'dietista-nutricionista-en-valladolid',
          'nutricion-deportiva-valladolid': 'dietista-nutricionista-en-valladolid', 'tips': 'blog-nutricion'}

WA = 'https://wa.me/34669877052?text=Hola%20Andrea%2C%20me%20gustar%C3%ADa%20pedir%20cita.%20%C2%BFQu%C3%A9%20huecos%20tienes%20disponibles%3F'
MAPS = 'https://maps.google.com/maps?q=Calle%20Gregorio%20Fernandez%204%2C%20Valladolid'
ARROW = '<svg class="i" viewBox="0 0 24 24"><path d="M7 7h10v10"/><path d="M7 17 17 7"/></svg>'

# ---------------------------------------------------------------- trocear el original
style_end = SRC.index('</style>') + len('</style>')
head_extra = SRC[:style_end]                       # <title>, metas, fuentes y <style>
body = SRC[style_end:]
main_open = body.index('<main id="app">') + len('<main id="app">')
main_close = body.index('</main>')
antes_main, main_html, despues_main = body[:main_open], body[main_open:main_close], body[main_close:]

starts = [m.start() for m in re.finditer(r'<div data-page="', main_html)]
segmentos = {}
for i, st in enumerate(starts):
    fin = starts[i + 1] if i + 1 < len(starts) else len(main_html)
    seg = main_html[st:fin]
    seg = re.sub(r'\n<!-- =+ [^>]* =+ -->\s*$', '\n', seg.rstrip() + '\n')  # quita el comentario de la siguiente
    slug = re.match(r'<div data-page="([^"]+)"', seg).group(1)
    segmentos[slug] = seg.replace(f'<div data-page="{slug}" hidden>', f'<div data-page="{slug}">', 1)
assert set(segmentos) == set(PAGINAS), set(segmentos) ^ set(PAGINAS)

# ids que existen en cada página (para resolver #anclas que apuntan a otra página)
for slug, seg in segmentos.items():
    for idm in re.finditer(r'\sid="([^"]+)"', seg):
        ANCLAS.setdefault(idm.group(1), f'{FILES[slug]}#{idm.group(1)}')

def resolver_href(token: str, slug_actual: str, ids_actuales: set) -> str | None:
    t = ALIAS.get(token, token)
    if not t or t in ids_actuales:
        return None
    if t in FILES:
        return './' if FILES[t] == 'index.html' else FILES[t]
    if t in ANCLAS:
        return ANCLAS[t].replace('index.html#', './#')
    return None

def reescribir_enlaces(html_txt: str, slug: str) -> str:
    ids = set(re.findall(r'\sid="([^"]+)"', segmentos[slug]))
    def sub(m):
        nuevo = resolver_href(html.unescape(m.group(1)), slug, ids)
        return f'href="{nuevo}"' if nuevo else m.group(0)
    return re.sub(r'href="#([^"]*)"', sub, html_txt)

def hero_estatico(seg: str) -> str:
    def sub(m):
        attrs = dict(re.findall(r'data-([a-z0-9]+)="([^"]*)"', m.group(0)))
        if attrs.get('extra') == 'booking':
            ctas = (f'<a class="btn btn-wa" href="{WA}" target="_blank" rel="noopener"><svg class="i" viewBox="0 0 24 24" data-ic="wa"></svg> Escribir por WhatsApp</a>'
                    f'<a class="btn btn-outline-light" href="tel:+34669877052"><svg class="i" viewBox="0 0 24 24" data-ic="phone"></svg> Llamar</a>')
        else:
            ctas = f'<a class="btn btn-secondary" href="#reservar">Encuentra tu consulta {ARROW}</a>'
            if attrs.get('extra') == 'contact':
                ctas += (f'<a class="btn btn-outline-light" href="{MAPS}" target="_blank" rel="noopener">Ver ubicación</a>'
                         f'<a class="btn btn-wa" href="{WA}" target="_blank" rel="noopener"><svg class="i" viewBox="0 0 24 24" data-ic="wa"></svg> Escribir por WhatsApp</a>')
            else:
                ctas += '<a class="btn btn-outline-light" href="#contacto">Pide tu cita</a>'
        return (m.group(0) + f'<img src="img/{attrs["hero"]}.webp" alt="" fetchpriority="high"><div class="page-hero-shade"></div><div class="wrap">'
                f'<nav class="crumbs" aria-label="Ruta"><a href="#inicio">Inicio</a><span>/</span><span class="cur">{attrs["crumb"]}</span></nav>'
                f'<p class="ey">{attrs["ey"]}</p><h1>{attrs["h1"]}</h1><p class="lead">{attrs["lead"]}</p><div class="ctas">{ctas}</div></div>')
    return re.sub(r'<section class="page-hero"[^>]*>(?=</section>)', sub, seg)

def lazy(html_txt: str) -> str:
    def sub(m):
        tag = m.group(0)
        if 'loading=' in tag or 'fetchpriority' in tag or 'logo.webp' in tag:
            return tag
        return tag[:-1] + ' loading="lazy" decoding="async">'
    return re.sub(r'<img\b[^>]*>', sub, html_txt)

def texto_plano(h: str) -> str:
    return re.sub(r'\s+', ' ', html.unescape(re.sub(r'<[^>]+>', ' ', h))).strip()

# ---------------------------------------------------------------- datos estructurados
NEGOCIO = {
    '@type': ['LocalBusiness', 'MedicalBusiness'], '@id': BASE + '#negocio',
    'name': 'Nutriendo Hábitos', 'alternateName': 'Nutriendo Hábitos · Andrea Argüello',
    'description': 'Consulta de dietética y nutrición en Valladolid y online: nutrición deportiva, nutrición oncológica, pérdida de peso, patología digestiva y educación nutricional.',
    'url': BASE, 'telephone': '+34 669 87 70 52', 'email': 'nutricion.arguello@gmail.com',
    'image': BASE + 'img/og.jpg', 'logo': BASE + 'img/logo.webp',
    'address': {'@type': 'PostalAddress', 'streetAddress': 'Calle Gregorio Fernández, 4', 'postalCode': '47006',
                'addressLocality': 'Valladolid', 'addressRegion': 'Castilla y León', 'addressCountry': 'ES'},
    'areaServed': [{'@type': 'City', 'name': 'Valladolid'}, {'@type': 'Country', 'name': 'España'}],
    'founder': {'@id': BASE + '#andrea'}, 'sameAs': ['https://www.instagram.com/nutrir_habitos/'],
    'knowsAbout': ['Nutrición deportiva', 'Nutrición oncológica', 'Pérdida de peso', 'Patología digestiva', 'Dietética', 'Bioimpedancia'],
}
ANDREA = {'@type': 'Person', '@id': BASE + '#andrea', 'name': 'Andrea Argüello Arbe', 'jobTitle': 'Dietista-nutricionista',
          'worksFor': {'@id': BASE + '#negocio'},
          'alumniOf': [{'@type': 'CollegeOrUniversity', 'name': 'Universidad de Valladolid'},
                       {'@type': 'CollegeOrUniversity', 'name': 'Universidad de Barcelona'}]}
WEB = {'@type': 'WebSite', '@id': BASE + '#web', 'url': BASE, 'name': 'Nutriendo Hábitos', 'inLanguage': 'es-ES', 'publisher': {'@id': BASE + '#negocio'}}

SERVICIOS = {
    'nutricion-deportiva-valladolid': 'Nutrición deportiva',
    'nutricion-oncologica-valladolid': 'Nutrición oncológica',
    'dietista-nutricionista-en-valladolid': 'Consulta de dietética y nutrición',
    'nutricionista-online': 'Consulta de nutrición online',
}

def jsonld(slug: str, url: str, titulo: str, desc: str, seg: str) -> str:
    graph = [NEGOCIO, ANDREA, WEB,
             {'@type': 'WebPage', '@id': url + '#pagina', 'url': url, 'name': titulo, 'description': desc,
              'isPartOf': {'@id': BASE + '#web'}, 'about': {'@id': BASE + '#negocio'}, 'inLanguage': 'es-ES'}]
    if slug != 'inicio':
        crumb = re.search(r'data-crumb="([^"]+)"', seg)
        nombre = crumb.group(1) if crumb else titulo.split('|')[0].strip()
        graph.append({'@type': 'BreadcrumbList', 'itemListElement': [
            {'@type': 'ListItem', 'position': 1, 'name': 'Inicio', 'item': BASE},
            {'@type': 'ListItem', 'position': 2, 'name': nombre, 'item': url}]})
    if slug in SERVICIOS:
        graph.append({'@type': 'Service', 'name': SERVICIOS[slug], 'serviceType': SERVICIOS[slug], 'url': url,
                      'provider': {'@id': BASE + '#negocio'},
                      'areaServed': [{'@type': 'City', 'name': 'Valladolid'}, {'@type': 'Country', 'name': 'España'}],
                      'availableChannel': {'@type': 'ServiceChannel', 'serviceLocation': {'@id': BASE + '#negocio'}}})
    faqs = re.findall(r'<details><summary>(.*?)</summary><div class="oc small">(.*?)</div></details>', seg, re.S)
    if faqs:
        graph.append({'@type': 'FAQPage', 'mainEntity': [
            {'@type': 'Question', 'name': texto_plano(q), 'acceptedAnswer': {'@type': 'Answer', 'text': texto_plano(a)}} for q, a in faqs]})
    return '<script type="application/ld+json">' + json.dumps({'@context': 'https://schema.org', '@graph': graph}, ensure_ascii=False) + '</script>'

# ---------------------------------------------------------------- montar cada página
SUBSERV = [('patologias', 'Tratamiento de patologías', 'stetho'), ('sobrepeso', 'Sobrepeso y ganancia de peso', 'scale'),
           ('nutricion-deportiva-valladolid', 'Nutrición deportiva', 'activity'), ('nutricion-oncologica-valladolid', 'Nutrición oncológica', 'heart'),
           ('vegetariana', 'Vegetariana y vegana', 'leaf')]
CHEV_DOWN = '<svg class="i" viewBox="0 0 24 24"><path d="m6 9 6 6 6-6"/></svg>'

def href_de(token: str, slug: str) -> str:
    if token in FILES:
        return './' if FILES[token] == 'index.html' else FILES[token]
    if slug == 'dietista-nutricionista-en-valladolid':
        return '#' + token
    return FILES['dietista-nutricionista-en-valladolid'] + '#' + token

def nav_html(slug: str, movil: bool = False) -> str:
    activo = NAVMAP.get(slug, slug)
    out = []
    for key, txt in NAV:
        href = './' if key == 'inicio' else FILES[key]
        cur = ' aria-current="page"' if key == activo else ''
        a = f'<a href="{href}" data-nav="{key}"{cur}>{txt}</a>'
        if key != 'dietista-nutricionista-en-valladolid':
            out.append(a)
        elif movil:
            subs = ''.join(f'<a href="{href_de(t, slug)}">{n}</a>' for t, n, _ in SUBSERV)
            out.append(a + f'<div class="m-sub">{subs}<a href="{FILES["nuestros-servicios"]}">Todos los servicios</a></div>')
        else:
            subs = ''.join(f'<a href="{href_de(t, slug)}"><svg class="i" viewBox="0 0 24 24" data-ic="{ic}"></svg>{n}</a>' for t, n, ic in SUBSERV)
            out.append(f'<div class="nav-dd">{a}<button type="button" class="dd-btn" aria-expanded="false" aria-label="Ver todos los servicios">{CHEV_DOWN}</button>'
                       f'<div class="dd-menu">{subs}<div class="sep"></div><a class="all" href="{FILES["nuestros-servicios"]}">Todos los servicios</a></div></div>')
    return ''.join(out)

if OUT.exists():
    shutil.rmtree(OUT)
OUT.mkdir(parents=True)

for slug, (archivo, titulo, desc, _prio) in PAGINAS.items():
    url = BASE if archivo == 'index.html' else BASE + archivo
    seg = hero_estatico(segmentos[slug])
    robots = 'noindex, nofollow' if MODO == 'prueba' else ('noindex, follow' if slug == 'proteccion-de-datos' else 'index, follow, max-image-preview:large')
    head = f'''<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>{html.escape(titulo)}</title>
<meta name="description" content="{html.escape(desc)}">
<meta name="robots" content="{robots}">
<link rel="canonical" href="{url}">
<meta name="author" content="Andrea Argüello Arbe">
<meta property="og:type" content="website">
<meta property="og:locale" content="es_ES">
<meta property="og:site_name" content="Nutriendo Hábitos">
<meta property="og:title" content="{html.escape(titulo)}">
<meta property="og:description" content="{html.escape(desc)}">
<meta property="og:url" content="{url}">
<meta property="og:image" content="{BASE}img/og.jpg">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta name="twitter:card" content="summary_large_image">
<meta name="theme-color" content="#56704F">
<link rel="preload" as="image" href="img/{'hero' if slug == 'inicio' else re.search(r'data-hero="([^"]+)"', seg).group(1) if 'data-hero=' in seg else 'logo'}.webp">
{jsonld(slug, url, titulo, desc, seg)}
<style>:root{{padding-top:env(safe-area-inset-top);padding-bottom:env(safe-area-inset-bottom)}}body{{margin:0}}img{{max-width:100%}}[hidden]{{display:none!important}}</style>
'''
    extra = re.sub(r'<title>.*?</title>\n', '', head_extra, flags=re.S).replace('<meta name="claude:viewport-fit-sides" content="cover">\n', '')
    cuerpo = antes_main + '\n' + seg + '\n' + despues_main
    cuerpo = cuerpo.replace('<nav class="main" aria-label="Principal"></nav>', f'<nav class="main" aria-label="Principal">{nav_html(slug)}</nav>')
    cuerpo = cuerpo.replace('<nav class="mobile-nav" aria-label="Menú móvil"></nav>', f'<nav class="mobile-nav" aria-label="Menú móvil">{nav_html(slug, True)}</nav>')
    mpa = {'page': slug, 'files': FILES_JS, 'anchors': {k: v.replace('index.html#', './#') for k, v in ANCLAS.items()}}
    cuerpo = cuerpo.replace('<script>\n(function(){', '<script>window.__MPA=' + json.dumps(mpa, ensure_ascii=False) + ';</script>\n<script>\n(function(){', 1)
    cuerpo = reescribir_enlaces(cuerpo, slug)
    cuerpo = lazy(cuerpo)
    if slug == 'inicio':
        cuerpo = cuerpo.replace('<img src="img/hero.webp" alt="Bowl saludable con verduras y legumbres" loading="lazy" decoding="async">',
                                '<img src="img/hero.webp" alt="Bowl saludable con verduras y legumbres, consulta de nutricionista en Valladolid" fetchpriority="high">')
    (OUT / archivo).write_text(head + extra + '\n</head>\n<body>\n' + cuerpo.strip() + '\n</body>\n</html>\n', encoding='utf-8')

# ---------------------------------------------------------------- resto de archivos
shutil.copytree(SITE / 'img', OUT / 'img')
shutil.copy(SITE / 'enviar.php', OUT / 'enviar.php')
shutil.copytree(SITE / 'reservas', OUT / 'reservas', ignore=shutil.ignore_patterns('*.json.php', '.limite_*', '.cerrojo'))
admin = (OUT / 'reservas' / 'admin.php').read_text(encoding='utf-8').replace('href="../#reservar"', 'href="../reservar.html"')
(OUT / 'reservas' / 'admin.php').write_text(admin, encoding='utf-8')

if MODO == 'definitiva':
    urls = ''.join(f'  <url><loc>{BASE if a == "index.html" else BASE + a}</loc><lastmod>{HOY}</lastmod><priority>{p}</priority></url>\n'
                   for s_, (a, t, d, p) in PAGINAS.items() if s_ != 'proteccion-de-datos')
    (OUT / 'sitemap.xml').write_text('<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n' + urls + '</urlset>\n', encoding='utf-8')
    (OUT / 'robots.txt').write_text(f'User-agent: *\nAllow: /\nDisallow: /reservas/\nDisallow: /nueva/\n\nSitemap: {BASE}sitemap.xml\n', encoding='utf-8')

print(MODO, '->', OUT, '|', len(PAGINAS), 'páginas')
