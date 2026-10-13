{{-- Identidad Dismatec (del e-commerce): tipografía Open Sans (vía ->font()), rojo #e2231a como
     primario (vía ->colors()) y estos acentos finos que replican el sistema de diseño del storefront:
     botones con peso fuerte y anillo de foco rojo suave. Sin barras ni líneas rojas. --}}
<style>
    :root {
        --dismatec-red: #e2231a;
        --dismatec-red-hover: #e74b43;
        --dismatec-ring: rgba(226, 35, 26, 0.14);
    }

    /* Botones con el peso del storefront (CTAs en 700) */
    .fi-btn {
        font-weight: 600;
    }

    /* Anillo de foco de marca (igual que .sf-btn:focus-visible del e-commerce) */
    .fi-btn:focus-visible,
    .fi-input:focus,
    .fi-select-input:focus {
        box-shadow: 0 0 0 4px var(--dismatec-ring) !important;
    }

    /* El tablero aprovecha todo el ancho: los widgets estiran a lo alto de su fila
       para que nada deje huecos grandes en pantallas anchas. */
    .fi-wi {
        height: 100%;
    }

    /* La rejilla de estadísticas llena el ancho disponible de forma pareja. */
    .fi-wi-stats-overview-stats-ctn {
        width: 100%;
    }

    /* Tarjetas de estadística: altura uniforme y acento rojo sutil al pasar el cursor,
       sin fondos rojos (se mantiene el look claro del storefront). */
    .fi-wi-stats-overview-stat {
        height: 100%;
        transition: border-color 0.15s ease, box-shadow 0.15s ease;
    }

    .fi-wi-stats-overview-stat:hover {
        border-color: var(--dismatec-red-hover);
        box-shadow: 0 1px 3px var(--dismatec-ring);
    }

    /* En móvil, reducir el relleno lateral de las secciones para ganar ancho útil. */
    @media (max-width: 640px) {
        .fi-section-content {
            padding: 0.875rem;
        }
    }
</style>
