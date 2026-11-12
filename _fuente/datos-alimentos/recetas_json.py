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
filas = []
for r in R:
    v, avisos = nutricion(r)
    assert not avisos, (r['t'], avisos)
    filas.append([r['t'], r['min'], r['rac'], r['tipo'], r['tags'], [[n, q, ('' if n in DESPENSA else fam(n)), opt(q)] for n, q in r['ing']], r['pasos'], v])
out = {'despensa': DESPENSA, 'grupos': [[k, [i for i in v if i in usadas]] for k, v in GRUPOS.items()], 'principal': PRINCIPAL, 'sin': SINONIMOS, 'r': filas}
json.dump(out, open(sys.argv[1], 'w', encoding='utf-8'), ensure_ascii=False, separators=(',', ':'))
print(len(filas), 'recetas con valor nutricional')
