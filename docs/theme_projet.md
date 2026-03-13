# Documentation du Thème - Gestion_Colis

## 1. Présentation du Thème

L'application **Gestion_Colis** utilise un thème visuel **futuriste et technologique** caractérisé par des effets lumineux néon, des dégradés modernes et une atmosphère spatiale. Ce thème a été conçu pour offrir une expérience utilisateur immersive et moderne, reflétant l'innovation technologique de la solution de gestion de colis.

L'identité visuelle s'articule autour d'une palette de couleurs cyberpunk/sci-fi avec des accents de cyan lumineux, de magenta néon et de violet, le tout sur un fond sombre profond. Les éléments d'interface sont sublimés par des effets de glow (lueur), des bordures lumineuses et des animations fluides qui donnent une impression de mouvement et de vie à l'application.

## 2. Palette de Couleurs

### Couleurs Principales

La palette de couleurs du thème a été soigneusement sélectionnée pour créer une ambiance technologique tout en maintenant une excellente lisibilité et un bon contraste pour l'interface utilisateur. Les couleurs primaires sont dominées par des teintes de cyan électrique et de bleu turquoise qui évoquent la technologie de pointe et la modernité.

| Couleur | Code Hexadécimal | Usage |
|---------|------------------|-------|
| `--tech-cyan` | `#00B4D8` | Couleur principale, actions principales, icônes, highlights |
| `--tech-blue` | `#33D9E1` | Complémentaire au cyan, dégradés, hover states |
| `--tech-purple` | `#8B5CF6` | Accents, éléments secondaires, badges spéciaux |
| `--tech-magenta` | `#FF00FF` | Éléments décoratifs, effets de glow secondaires |

### Couleurs de Fond

Les fonds de l'application utilisent des tons sombres profonds qui créent un contraste parfait avec les éléments lumineux néon. Cette approche de "dark mode" par défaut est non seulement esthétiquement moderne mais elle réduit également la fatigue oculaire lors d'une utilisation prolongée de l'application.

| Couleur | Code Hexadécimal | Usage |
|---------|------------------|-------|
| `--blue-night` | `#0F172A` | Fond principal de l'application |
| `--blue-slate` | `#1E293B` | Fond secondaire, en-têtes, sections alternatives |
| `--gray-dark` | `#334155` | Fond tertiaire, zones de saisie, tableaux |
| `--dark-slate` | `#1A1A1A` | Fond de la sidebar, panneaux latéraux |
| `--dark-card` | `#2C3135` | Fond des cartes, conteneurs, modales |

### Couleurs de Texte

La hiérarchie visuelle du texte est établie grâce à trois niveaux de luminosité qui permettent de distinguer clairement les titres, le texte principal et les informations secondaires. Cette structure garantit une lecture confortable et une navigation intuitive dans l'interface.

| Couleur | Code Hexadécimal | Usage |
|---------|------------------|-------|
| `--text-primary` | `#FFFFFF` | Titres, textes importants, labels |
| `--text-secondary` | `#E2E8F0` | Texte de corps, paragraphes |
| `--text-muted` | `#94A3B8` | Informations secondaires, placeholders, métadonnées |

### Couleurs Fonctionnelles

Les couleurs fonctionnelles sont utilisées pour communiquer l'état du système et fournir des retours visuels immédiat à l'utilisateur. Chaque couleur est associée à un sens sémantique précis qui est respecté dans toute l'application.

| Couleur | Code Hexadécimal | Signification | Usage |
|---------|------------------|---------------|-------|
| `--success` | `#22C55E` | Succès, validation | Badges "livré", confirmations, actions réussies |
| `--warning` | `#F59E0B` | Attention, attente | Badges "en attente", alertes non critiques |
| `--error` | `#EF4444` | Erreur, problème | Badges "annulé", erreurs, actions dangereuses |
| `--info` | `#3B82F6` | Information | Badges "en livraison", notifications informatives |

## 3. Effets Visuels et Animations

### Effets de Glow (Lueur)

Les effets de lueur sont un élément central du thème et contribuent significativement à l'identité visuelle de l'application. Ces effets sont appliqués principalement aux éléments interactifs et aux éléments d'accentuation pour créer une sensation de profondeur et de modernité.

```
--glow-cyan: rgba(0, 229, 255, 0.3);
--glow-cyan-strong: rgba(0, 229, 255, 0.6);
--glow-success: rgba(34, 197, 94, 0.3);
--glow-error: rgba(239, 68, 68, 0.3);
--border-color: rgba(0, 229, 255, 0.2);
```

Ces propriétés sont utilisées pour créer des ombres lumineuses autour des boutons, des cartes, des champs de formulaire et des éléments de navigation. L'effet de glow renforce la perception de l'interactivité et guide l'attention de l'utilisateur vers les éléments importants.

### Animations et Transitions

Les animations de l'application sont conçues pour être fluides et subtiles, améliorant l'expérience utilisateur sans distraire de la fonctionnalité principale. Toutes les transitions utilisent des courbes d'accélération douces pour créer un mouvement naturel.

```css
--transition-fast: 0.2s ease;
--transition-normal: 0.3s ease;
--transition-slow: 0.5s ease;
```

Les principales animations incluent le fade-in des éléments au chargement, le slide-up des modales, le pulse des boutons principaux, le float des éléments décoratifs et le smooth-scroll pour la navigation. Ces animations sont appliquées de manière cohérente dans toute l'application pour maintenir une expérience utilisateur unifiée.

### Bordures et Séparateurs

Les bordures de l'application utilisent des couleurs semi-transparentes basées sur le cyan, créant des délimitations subtiles entre les éléments tout en maintenant l'ambiance néon du thème. Les bordures sont particulièrement visibles sur les cartes, les champs de formulaire et les éléments de navigation.

## 4. Typographie

### Familles de Police

Le thème utilise deux familles de polices complémentaires pour créer une hiérarchie visuelle claire. La police principale (font-primary) est utilisée pour le texte courant tandis que la police d'affichage (font-display) est réservée aux titres et aux éléments de grande taille.

```css
--font-primary: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
--font-display: 'Poppins', sans-serif;
```

La police **Inter** est une police sans-serif moderne et lisible, parfaitement adaptée aux interfaces utilisateur numériques. La police **Poppins** est une police géométrique avec une personnalité distincte, idéale pour créer des titres percutants et mémorables. Pour les pages d'atterrissage (index, login, register), des polices supplémentaires sont utilisées pour renforcer l'aspect futuriste.

```css
/* Pages marketing et authentification */
--font-display-landing: 'Orbitron', sans-serif;
--font-body-landing: 'Rajdhani', sans-serif;
```

La police **Orbitron** est une police de style sci-fi avec des angles géométriques prononcés, parfaite pour les titres de grande taille. La police **Rajdhani** est une police technique moderne avec une excellente lisibilité, utilisée pour les paragraphes et les descriptions.

### Tailles de Police

L'échelle typographique de l'application suit une progression mathématique pour assurer une hiérarchie visuelle cohérente. Les titres utilisent des tailles plus grandes avec la police d'affichage, tandis que le corps de texte utilise des tailles plus modestes avec la police principale.

| Élément | Taille | Police | Usage |
|---------|--------|--------|-------|
| H1 | 2rem (32px) | Poppins | Titres de page principaux |
| H2 | 1.5rem (24px) | Poppins | Titres de section |
| H3 | 1.25rem (20px) | Poppins | Titres de cartes |
| Corps | 1rem (16px) | Inter | Texte principal |
| Petit | 0.875rem (14px) | Inter | Texte secondaire, métadonnées |
| Très petit | 0.75rem (12px) | Inter | Labels, badges |

## 5. Composants UI

### Boutons

Les boutons du thème sont caractérisés par des dégradés linéaires cyan-bleu et des effets de glow subtils. Ils existent en plusieurs variantes pour distinguer les niveaux d'importance des actions. Les boutons primaires utilisent un fond dégradé du cyan vers le bleu avec une ombre lumineuse, tandis que les boutons secondaires utilisent un fond transparent avec une bordure cyan.

**Bouton Primaire :**
- Fond : `linear-gradient(135deg, var(--tech-cyan), var(--tech-blue))`
- Couleur du texte : `#000` (noir pour le contraste)
- Ombre : `0 4px 15px var(--glow-cyan)`
- Effet hover : translation vers le haut et intensification de l'ombre

**Bouton Secondaire :**
- Fond : `rgba(0, 229, 255, 0.1)`
- Bordure : `2px solid var(--tech-cyan)`
- Couleur du texte : `var(--tech-cyan)`
- Effet hover : fond devient opaque, texte devient noir

### Cartes

Les cartes sont les conteneurs principaux de l'interface et sont utilisées pour regrouper des informations connexes. Elles utilisent un fond sombre avec une bordure semi-transparente et un effet de hover qui fait apparaître une lueur cyan.

- Fond : `var(--bg-card)` avec opacité
- Bordure : `1px solid var(--border-color)`
- Rayon : `var(--radius-md)` (12px)
- Effet hover : `border-color` devient plus visible, `box-shadow` avec glow

### Badges et Étiquettes

Les badges sont utilisés pour afficher le statut des éléments (colis, utilisateurs, agents) de manière visuelle et immédiatement reconnaissable. Chaque statut possède une couleur associée parmi les couleurs fonctionnelles.

- Style : `border-radius: var(--radius-full)` (pillule)
- Padding : `var(--spacing-xs) var(--spacing-sm)`
- Police : `font-weight: 600`, `text-transform: uppercase`
- Tailles disponibles : petit (0.75rem) et normal (0.875rem)

### Formulaires

Les champs de formulaire sont conçus avec un fond sombre semi-transparent et une bordure qui réagit au focus. L'interface est optimisée pour la saisie avec des labels clairs et des feedbacks visuels appropriés.

- Fond : `rgba(15, 23, 42, 0.8)`
- Bordure : `2px solid rgba(0, 240, 255, 0.2)`
- Focus : `border-color: var(--tech-cyan)`, `box-shadow: 0 0 0 3px var(--glow-cyan)`
- Rayon : `var(--radius-md)` (12px)

### Tableaux

Les tableaux affichent des données tabulaires avec un style cohérent qui maintient la lisibilité même sur de grandes quantités d'informations. L'en-tête utilise un fond plus clair pour la distinction, et chaque ligne réagit au survol avec un léger highlight.

- En-tête : `background: var(--bg-tertiary)`, `text-transform: uppercase`
- Lignes : `border-bottom: 1px solid var(--border-color)`
- Hover : `background: rgba(0, 229, 255, 0.05)`

## 6. Structure de Layout

### Layout Principal

L'application utilise un layout classique avec une sidebar fixe sur la gauche et un contenu principal qui s'adapte à la largeur disponible. Cette structure permet une navigation intuitive et un accès rapide aux fonctionnalités principales.

```
┌─────────────────────────────────────────────┐
│                  Header                      │
├──────────────┬──────────────────────────────┤
│   Sidebar    │                              │
│              │       Content Area           │
│  - Logo      │                              │
│  - Nav       │                              │
│  - Footer    │                              │
│              │                              │
└──────────────┴──────────────────────────────┘
```

### Sidebar

La sidebar est un élément de navigation permanent qui reste visible pendant la navigation dans l'application. Elle contient le logo de l'application, les liens de navigation regroupés par section, et les informations utilisateur en bas de page.

- Largeur : `var(--sidebar-width)` (280px)
- Fond : `var(--bg-sidebar)`
- Éléments de navigation : `nav-link` avec icône et texte
- Section titres : `nav-section-title` avec lettres majuscules espacées
- État collapsed : largeur réduite à 70px avec affichage réduit

### Header

Le header est une barre horizontale fixée en haut de l'application qui contient les éléments d'en-tête et les contrôles utilisateur. Elle inclut le bouton de menu mobile, le titre de la page, la barre de recherche et le profil utilisateur.

- Hauteur : `var(--header-height)` (80px)
- Fond : `background: rgba(10, 14, 23, 0.8)` avec effet blur
- Bordure inférieure : `1px solid var(--border-color)`

## 7. Responsive Design

### Points de Rupture

L'application est conçue pour s'adapter à différentes tailles d'écran, depuis les grands écrans de bureau jusqu'aux appareils mobiles. Les breakpoints sont définis pour assurer une expérience utilisateur optimale sur chaque type de dispositif.

| Breakpoint | Largeur | Comportement |
|------------|---------|--------------|
| XL | ≥ 1280px | Layout complet, sidebar expandue |
| LG | ≥ 1024px | Layout complet, ajustements mineurs |
| MD | ≥ 768px | Sidebar collapsible sur mobile |
| SM | < 640px | Menu hamburger, cartes empilées |

### Adaptations Mobile

Sur les petits écrans, la sidebar se transforme en panneau latéral masquable accessible via un bouton de menu. Les cartes de statistiques passent d'une disposition en ligne à une disposition en colonne. Les tableaux disposent d'un scroll horizontal pour maintenir la lisibilité des données.

```css
@media (max-width: 1024px) {
    .sidebar { transform: translateX(-100%); }
    .sidebar.open { transform: translateX(0); }
    .main-content { margin-left: 0; }
}
```

## 8. Icônes et Graphismes

### Bibliothèque d'Icônes

L'application utilise **Font Awesome 6** comme bibliothèque d'icônes principale. Cette bibliothèque offre une vaste gamme d'icônes cohérentes et reconnaissables pour représenter les différentes fonctionnalités et sections de l'application.

Les icônes sont systématiquement accompagnées de texte pour assurer une compréhension claire de leur fonction. Elles sont colorées en `--tech-cyan` par défaut et changent de couleur au survol pour indiquer l'interactivité.

### Images et Illustrations

Les images utilisées dans l'application suivent des conventions spécifiques. Les avatars utilisateurs sont affichés dans des cercles avec un fond dégradé. Les images de marque sont optimisées pour le web avec des formats modernes (WebP, PNG avec transparence).

## 9. Accessibilité

### Contraste et Lisibilité

Le thème a été conçu pour respecter les normes d'accessibilité WCAG 2.1 niveau AA. Les rapports de contraste entre le texte et les fonds sont vérifiés pour garantir une lisibilité optimale pour tous les utilisateurs, y compris ceux ayant des troubles de la vision des couleurs.

- Contraste texte principal sur fond sombre : 7:1 minimum
- Contraste texte secondaire sur fond sombre : 4.5:1 minimum
- Contraste texte sur boutons : 4.5:1 minimum

### Navigation au Clavier

Tous les éléments interactifs sont accessibles via la navigation au clavier. Les états de focus sont clairement visibles avec un outline cyan qui permet aux utilisateurs de naviguer efficacement dans l'application sans utiliser de souris.

### Support des Couleurs

Le thème ne repose pas uniquement sur la couleur pour communiquer l'information. Les badges de statut utilisent non seulement des couleurs différentes mais aussi des icônes et du texte pour assurer que l'information reste accessible aux utilisateurs daltoniens.

## 10. Personnalisation Future

### Modification des Couleurs

Pour modifier les couleurs principales du thème tout en conservant la cohérence visuelle, il suffit de modifier les variables CSS dans le fichier `variables.css`. Il est recommandé de maintenir les relations de contraste existantes lors de toute personnalisation.

### Ajout de Nouvelles Sections

Pour ajouter de nouvelles sections au thème, il faut respecter les conventions de nommage CSS et utiliser les variables de couleurs existantes. Les nouveaux composants doivent hériter des styles de base (`card`, `btn`, `form-control`) et ajouter des styles spécifiques uniquement si nécessaire.

### Thèmes Alternatifs

L'infrastructure CSS utilise des variables CSS natives qui permettent théoriquement l'implémentation de thèmes alternatifs (mode clair, thèmes personnalisés). Un sélecteur `[data-theme]` est déjà prévu dans le fichier des variables pour faciliter cette extension future.
