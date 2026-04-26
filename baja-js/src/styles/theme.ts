const theme = {
  grid: {
    container: '100rem',
    gutter: '2.4rem'
  },
  border: {
    radius: '1.6rem',
    radiusSmall: '0.8rem'
  },
  font: {
    family:
      "Poppins, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, 'Open Sans', 'Helvetica Neue', sans-serif",
    familyUrzeit: "'Urzeit', Poppins, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, 'Open Sans', 'Helvetica Neue', sans-serif",
    light: 300,
    normal: 400,
    medium: 500,
    bold: 700,
    sizes: {
      xsmall: '1.2rem',
      small: '1.4rem',
      medium: '1.6rem',
      large: '2.0rem',
      xlarge: '2.4rem',
      xxlarge: '3.2rem',
      xxxlarge: '4.0rem',
      huge: '5.0rem'
    }
  },
  colors: {
    primary: '#00ff66', // Verde neon
    darkPrimary: '#00cc52', // Verde escuro
    lightPrimary: '#66ff99', // Verde claro
    secondary: '#f4c430', // Amarelo vibrante
    lightSecondary: 'rgb(245, 206, 97)', // Amarelo claro
    darkSecondary: '#d1a700', // Amarelo escuro
    background: '#0b2746', // Fundo principal
    cardBackground: '#001f3f',
    heading: '#f4c430',
    text: '#ffffff',
    mutedText: '#d1d5db', // Gray-300
    border: '#00ff66',
    white: '#ffffff',
    black: '#000000',
    gray: 'rgb(83, 83, 83)',
  },
  transitions: {
    default: '0.3s ease-in-out',
    fast: '0.15s ease-in-out',
    slow: '0.5s ease-in-out'
  },
  spacings: {
    xxsmall: '0.8rem',
    xsmall: '1.6rem',
    small: '2.4rem',
    medium: '3.2rem',
    large: '4.0rem',
    xlarge: '6.4rem',
    xxlarge: '8.0rem'
  },
  layers: {
    base: 10,
    menu: 20,
    overlay: 30,
    modal: 40,
    alwaysOnTop: 50
  },
  shadows: {
    default: '0 4px 12px rgba(0, 0, 0, 0.3)',
    card: '0 8px 20px rgba(0, 255, 102, 0.2)'
  }
} as const;

export default theme;
