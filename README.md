# Nutriendo Hábitos

Web de Andrea Argüello Arbe, dietista-nutricionista en Valladolid.

- Página estática: `index.html` + imágenes en `img/`.
- `enviar.php`: envía el formulario de contacto a nutricion.arguello@gmail.com con el asunto «RESERVA WEB».
- `reservas/`: calendario de citas.
  - `api.php`: días y horas libres, y reservas de 45 min con motivo de consulta. La reserva bloquea el hueco al momento y queda pendiente hasta que Andrea la confirma.
  - `admin.php`: panel privado de Andrea (confirmar o rechazar citas, horario presencial/online, «reservas abiertas hasta», días con horario especial, días bloqueados, historial con buscador y descarga en Excel). La primera vez pide crear la contraseña.
  - `datos/`: guarda la configuración y las citas en el servidor. No se sube a GitHub y está protegido contra lectura web.
- Necesita PHP (hosting de DonDominio). Sin base de datos, sin servicios externos, sin claves en el código.
