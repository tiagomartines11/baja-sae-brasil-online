'use client'

import { createGlobalStyle, css } from 'styled-components'

const GlobalStyles = createGlobalStyle`
	
	@font-face {
		font-display: swap; /* Check https://developer.mozilla.org/en-US/docs/Web/CSS/@font-face/font-display for other options. */
		font-family: 'Poppins';
		font-style: normal;
		font-weight: 300;
		src: url('/fonts/poppins-v22-latin-300.woff2') format('woff2'); /* Chrome 36+, Opera 23+, Firefox 39+, Safari 12+, iOS 10+ */
	}

	
	@font-face {
		font-display: swap; /* Check https://developer.mozilla.org/en-US/docs/Web/CSS/@font-face/font-display for other options. */
		font-family: 'Poppins';
		font-style: normal;
		font-weight: 400;
		src: url('/fonts/poppins-v22-latin-regular.woff2') format('woff2'); /* Chrome 36+, Opera 23+, Firefox 39+, Safari 12+, iOS 10+ */
	}

	/* poppins-500 - latin */
@font-face {
  font-display: swap; /* Check https://developer.mozilla.org/en-US/docs/Web/CSS/@font-face/font-display for other options. */
  font-family: 'Poppins';
  font-style: normal;
  font-weight: 500;
  src: url('/fonts/poppins-v22-latin-500.woff2') format('woff2'); /* Chrome 36+, Opera 23+, Firefox 39+, Safari 12+, iOS 10+ */
}

	@font-face {
		font-display: swap; /* Check https://developer.mozilla.org/en-US/docs/Web/CSS/@font-face/font-display for other options. */
		font-family: 'Poppins';
		font-style: normal;
		font-weight: 600;
		src: url('/fonts/poppins-v22-latin-600.woff2') format('woff2'); /* Chrome 36+, Opera 23+, Firefox 39+, Safari 12+, iOS 10+ */
	}
	
	@font-face {
    font-family: 'Urzeit'; 
    src: url('../fonts/Urzeit-Personal_Use.ttf') format('truetype');
    font-weight: normal; 
    font-style: normal; 
}

  * {
      margin: 0;
			padding: 0;
			box-sizing: border-box;
			-webkit-font-smoothing: antialiased;
  		-moz-osx-font-smoothing: grayscale;
  }

	${({ theme }) => css`
    html {
      font-size: 62.5%;
    }

    body {
      font-family: ${theme.font.family};
      font-size: ${theme.font.sizes.medium};
    }
  `}
	
`
export default GlobalStyles
