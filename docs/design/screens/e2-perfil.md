# Perfil y segundo factor de la cuenta

TASK-109-b · CUS-78 · RF-040, RF-049 y RF-051 · SDD §4.3.2.

«Mi perfil» se abre desde las áreas autenticadas de plataforma, clínica y portal. La página muestra el correo de la cuenta y permite editar el nombre con PATCH /me/profile. PUT /me/password exige contraseña actual, nueva y confirmación. Se vacían los campos de contraseña al guardar.

«Regenerar códigos de recuperación» y «Desactivar segundo factor» requieren confirmación y un código TOTP de seis dígitos. Los administradores de plataforma y clínica no pueden desactivar el segundo factor. Los códigos devueltos al regenerar se muestran en pantalla una vez, sin almacenamiento en el navegador. Los errores se muestran junto al campo o como aviso general.

Se reutilizan PageHeader, Field, PasswordField y ConfirmDialog con el diseño existente.
