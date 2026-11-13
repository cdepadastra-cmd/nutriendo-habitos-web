# Genera recetas.json (lo que lee la herramienta "¿Qué cocino con lo que tengo?") a partir de recetas.py,
# con el valor nutricional aproximado por ración (nutricion.py). Uso: python3 recetas_json.py salida.json
import json, sys, os
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from recetas import R, GRUPOS, DESPENSA, FAMILIA, PRINCIPAL, SINONIMOS
from nutricion import nutricion
fams = {i for g in GRUPOS.values() for i in g}; fam = lambda n: FAMILIA.get(n, n)
malos = [(r['t'], n) for r in R for n, q in r['ing'] if n not in DESPENSA and fam(n) not in fams]
assert not malos, malos
usadas = {fam(n) for r in R for n, q in r['ing'] if n not in DESPENSA}
opt = lambda q: 1 if 'opcional' in q.lower() else 0
# Etiquetas que se calculan solas a partir de los ingredientes (no se fían de la escritura a mano)
CARNE_PESCADO = {'pollo', 'pavo', 'carne picada', 'lomo de cerdo', 'jamón', 'chorizo', 'salmón', 'merluza', 'bacalao', 'atún en lata',
                 'sardinas en lata', 'gambas', 'mejillones', 'caldo de pollo'}
ANIMAL = CARNE_PESCADO | {'huevos', 'leche', 'yogur natural', 'queso fresco', 'queso rallado', 'queso feta', 'mozzarella', 'nata'}
LACTEO = {'leche', 'yogur natural', 'queso fresco', 'queso rallado', 'queso feta', 'mozzarella', 'nata'}
GLUTEN = {'pasta', 'pan', 'cuscús', 'tortillas de trigo', 'harina', 'salsa de soja', 'avena'}
def etiquetas(r):
    obl = [n for n, q in r['ing'] if not opt(q)]
    t = [x for x in r['tags'] if x not in ('vegana', 'vegetariana')]
    if not any(n in ANIMAL for n in obl): t = ['vegana', 'vegetariana'] + t
    elif not any(n in CARNE_PESCADO for n in obl): t = ['vegetariana'] + t
    if 'sin gluten' in t and any(n in GLUTEN for n in obl): t.remove('sin gluten')
    if 'sin lactosa' in t and any(n in LACTEO for n in obl): t.remove('sin lactosa')
    return t
# 5.º elemento de cada ingrediente: 1 si es de origen animal (para avisar en las veganas con un opcional animal)
filas = []
for r in R:
    v, avisos = nutricion(r)
    assert not avisos, (r['t'], avisos)
    filas.append([r['t'], r['min'], r['rac'], r['tipo'], etiquetas(r), [[n, q, ('' if n in DESPENSA else fam(n)), opt(q), 1 if n in ANIMAL else 0] for n, q in r['ing']], r['pasos'], v])
out = {'despensa': DESPENSA, 'grupos': [[k, [i for i in v if i in usadas]] for k, v in GRUPOS.items()], 'principal': PRINCIPAL, 'sin': SINONIMOS, 'r': filas}
json.dump(out, open(sys.argv[1], 'w', encoding='utf-8'), ensure_ascii=False, separators=(',', ':'))
print(len(filas), 'recetas con valor nutricional')
