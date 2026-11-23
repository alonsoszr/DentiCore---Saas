# Restablecimiento de acceso y cancelación de clínica

TASK-109-b · CUS-02 y CUS-79 · RF-020 y RF-052 · SDD §3.4, §4.3.1 y §4.3.2.

En Usuarios, un administrador de clínica puede desbloquear o restablecer el segundo factor de otra cuenta de su clínica. En el detalle de la clínica, el Súper Administrador puede hacer lo mismo sobre el primer administrador. Cada acción confirma su efecto antes de usar la ruta correspondiente. Un restablecimiento obliga a configurar el segundo factor al volver a ingresar.

En el detalle de una clínica activa o suspendida, «Cancelar clínica» abre un diálogo con un motivo de 3 a 500 caracteres. El diálogo avisa que se revoca el acceso operativo y que la clínica conserva el plazo de 90 días para solicitar su exportación. La respuesta de la API actualiza el detalle y la lista. La generación del ZIP depende de TASK-139.

Los controles se deshabilitan mientras el servidor procesa la acción. Los errores se muestran dentro del diálogo y las confirmaciones correctas se anuncian con una región de estado.
