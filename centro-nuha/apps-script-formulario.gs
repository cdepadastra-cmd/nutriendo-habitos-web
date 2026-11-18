// Formulario de contacto de centronuha.es -> correo del centro.
// Pegar en script.google.com con la cuenta de correo del centro y desplegar como
// «Aplicación web» (Ejecutar como: Yo · Quién tiene acceso: Cualquier usuario).
function doPost(e) {
  var p = (e && e.parameter) || {};
  if (p.web) return ContentService.createTextOutput('ok');              // anti-spam
  var nombre = String(p.nombre || '').slice(0, 80);
  var tel = String(p.telefono || '').slice(0, 20);
  var email = String(p.email || '').slice(0, 120);
  var esp = String(p.especialidad || 'Consulta').slice(0, 60);
  var msg = String(p.mensaje || '').slice(0, 2000);
  if (!nombre || !tel || !msg) return ContentService.createTextOutput('faltan datos');
  var cuerpo = 'Nuevo mensaje desde la web de Centro Nuha\n\n' +
    'Nombre: ' + nombre + '\nTeléfono: ' + tel + '\nCorreo: ' + (email || '—') +
    '\nEspecialidad: ' + esp + '\n\nMensaje:\n' + msg + '\n\nPágina: ' + (p.pagina || '');
  var opciones = { name: 'Web Centro Nuha' };
  if (/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) opciones.replyTo = email;
  MailApp.sendEmail(Session.getEffectiveUser().getEmail(), 'Web Centro Nuha · ' + esp + ' · ' + nombre, cuerpo, opciones);
  return ContentService.createTextOutput('ok');
}
