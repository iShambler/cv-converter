# Instrucciones para configurar las plantillas

## Copiar plantillas

Copia estos archivos a la carpeta `C:\xampp99\htdocs\cv-converter\templates\`:

Desde `C:\Proyectos\Curriculums\`:

1. `Accenture 2025.docx` → renombrar a `Accenture_2025.docx`
2. `Arelance.docx` → mantener nombre `Arelance.docx`
3. `Avanade.doc` → **convertir a .docx** (abrir en Word > Guardar como > .docx) → `Avanade.docx`
4. `EVIDEN.DOCX` → renombrar a `EVIDEN.docx`
5. `Inetum.doc` → **convertir a .docx** (abrir en Word > Guardar como > .docx) → `Inetum.docx`
6. `Informe Ricoh- Nombre A. A. (plantilla CV).docx` → renombrar a `Ricoh.docx`
7. `Plantilla_ATOS.DOCX` → renombrar a `Plantilla_ATOS.docx`

## Configurar API Key

Editar `config.php` y pegar la API key de Claude en:
```php
define('CLAUDE_API_KEY', 'sk-ant-...');
```

## Acceder

Abrir en el navegador: http://localhost/cv-converter/
