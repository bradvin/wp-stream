( function () {
	const config = window.wpStreamAdminChat;

	if ( ! config ) {
		return;
	}

	const form = document.getElementById( 'wp-stream-chat-form' );
	const input = document.getElementById( 'wp-stream-chat-input' );
	const log = document.getElementById( 'wp-stream-chat-log' );
	const clearButton = document.getElementById( 'wp-stream-chat-clear' );
	const notice = document.getElementById( 'wp-stream-chat-notice' );
	const spinner = document.getElementById( 'wp-stream-chat-spinner' );
	const model = document.getElementById( 'wp-stream-chat-model' );
	const streamingToggle = document.getElementById( 'wp-stream-enable-streaming' );
	const systemPrompt = document.getElementById( 'wp-stream-system-prompt' );
	const maxTokens = document.getElementById( 'wp-stream-max-tokens' );

	if ( ! form || ! input || ! log || ! clearButton || ! notice || ! spinner ) {
		return;
	}

	const state = {
		messages: [],
		isStreaming: false,
	};
	let waitTimerId = null;

	const isStreamingEnabled = () => ! streamingToggle || streamingToggle.checked;
	const getTimestamp = () => Date.now();

	const setNotice = ( type, message ) => {
		if ( ! message ) {
			notice.className = 'notice inline hidden';
			notice.innerHTML = '';
			return;
		}

		notice.className = 'notice inline notice-' + type;
		notice.innerHTML = '<p>' + escapeHtml( message ) + '</p>';
	};

	const setModelDetails = ( details ) => {
		if ( ! model || ! details || typeof details !== 'object' ) {
			return;
		}

		const label = details.label || details.name || details.id || '';

		if ( ! label ) {
			return;
		}

		model.textContent = label;
		model.classList.toggle( 'is-unavailable', details.isAvailable === false );
	};

	const formatDuration = ( milliseconds ) => {
		if ( typeof milliseconds !== 'number' || milliseconds < 0 ) {
			return '';
		}

		if ( milliseconds < 1000 ) {
			return Math.round( milliseconds ) + 'ms';
		}

		const seconds = milliseconds / 1000;

		if ( seconds < 10 ) {
			return seconds.toFixed( 2 ) + 's';
		}

		if ( seconds < 60 ) {
			return seconds.toFixed( 1 ) + 's';
		}

		const minutes = Math.floor( seconds / 60 );
		const remainder = Math.round( seconds % 60 );

		return minutes + 'm ' + remainder + 's';
	};

	const setBusy = ( isBusy ) => {
		state.isStreaming = isBusy;
		form.querySelector( 'button[type="submit"]' ).disabled = isBusy;
		input.disabled = isBusy;
		clearButton.disabled = isBusy;
		spinner.classList.toggle( 'is-active', isBusy );

		if ( waitTimerId ) {
			window.clearInterval( waitTimerId );
			waitTimerId = null;
		}

		if ( isBusy ) {
			waitTimerId = window.setInterval( render, 1000 );
		}
	};

	const render = () => {
		if ( ! state.messages.length ) {
			log.innerHTML = '<div class="wp-stream-chat__empty">Send a message to start the chat.</div>';
			return;
		}

		log.innerHTML = state.messages
			.map( ( message ) => {
				const classes = [ 'wp-stream-chat__message', 'is-' + message.role ];

				if ( message.pending ) {
					classes.push( 'is-pending' );
				}

				if ( message.error ) {
					classes.push( 'is-error' );
				}

				return (
					'<article class="' + classes.join( ' ' ) + '">' +
						'<div class="wp-stream-chat__meta">' +
							'<span>' + escapeHtml( message.role === 'user' ? 'You' : 'Assistant' ) + '</span>' +
						'</div>' +
						'<div class="wp-stream-chat__content">' + renderMessageContent( message ) + '</div>' +
					'</article>'
				);
			} )
			.join( '' );

		log.scrollTop = log.scrollHeight;
	};

	const renderMessageContent = ( message ) => {
		if ( message.error ) {
			return '<p>' + escapeHtml( message.error ) + '</p>';
		}

		if ( ! message.content && message.pending ) {
			const seconds = typeof message.pendingSince === 'number'
				? Math.max( 0, Math.floor( ( Date.now() - message.pendingSince ) / 1000 ) )
				: 0;
			const label = message.streaming ? 'Waiting for streamed output' : 'Waiting for response';

			return '<p class="wp-stream-chat__typing">' + label + '… ' + seconds + 's</p>';
		}

		return '<p>' + escapeHtml( message.content || '' ).replaceAll( '\n', '<br>' ) + '</p>' + renderTimingMetadata( message );
	};

	const renderTimingMetadata = ( message ) => {
		if ( message.pending || message.error || ! message.timing ) {
			return '';
		}

		const startedAt = message.timing.startedAt;
		const completedAt = message.timing.completedAt;

		if ( typeof startedAt !== 'number' || typeof completedAt !== 'number' ) {
			return '';
		}

		const parts = [];

		if ( message.streaming && typeof message.timing.firstStreamAt === 'number' ) {
			parts.push( 'First stream: ' + formatDuration( message.timing.firstStreamAt - startedAt ) );
		}

		parts.push( 'Full response: ' + formatDuration( completedAt - startedAt ) );

		return '<div class="wp-stream-chat__timing">' + parts.map( escapeHtml ).join( ' · ' ) + '</div>';
	};

	const escapeHtml = ( value ) => {
		return value
			.replaceAll( '&', '&amp;' )
			.replaceAll( '<', '&lt;' )
			.replaceAll( '>', '&gt;' )
			.replaceAll( '"', '&quot;' )
			.replaceAll( "'", '&#039;' );
	};

	const createAssistantPlaceholder = () => {
		const startedAt = getTimestamp();

		return {
			role: 'assistant',
			content: '',
			pending: true,
			pendingSince: startedAt,
			streaming: isStreamingEnabled(),
			timing: {
				startedAt,
				firstStreamAt: null,
				completedAt: null,
			},
		};
	};

	const getAssistantMessage = () => {
		for ( let index = state.messages.length - 1; index >= 0; index -= 1 ) {
			if ( state.messages[ index ].role === 'assistant' ) {
				return state.messages[ index ];
			}
		}

		return null;
	};

	const markRequestStarted = () => {
		const assistantMessage = getAssistantMessage();

		if ( ! assistantMessage || ! assistantMessage.timing ) {
			return;
		}

		const startedAt = getTimestamp();

		assistantMessage.pendingSince = startedAt;
		assistantMessage.timing.startedAt = startedAt;
		assistantMessage.timing.firstStreamAt = null;
		assistantMessage.timing.completedAt = null;
	};

	const handleStreamFrame = ( frame ) => {
		if ( ! frame || typeof frame !== 'object' ) {
			return;
		}

		const assistantMessage = getAssistantMessage();

		if ( frame.type === 'start' ) {
			setModelDetails( frame.payload?.model );
			return;
		}

		if ( frame.type === 'delta' && assistantMessage ) {
			const text = String( frame.payload?.text || '' );

			if ( text && assistantMessage.timing && typeof assistantMessage.timing.firstStreamAt !== 'number' ) {
				assistantMessage.timing.firstStreamAt = getTimestamp();
			}

			assistantMessage.content += text;
			render();
			return;
		}

		if ( frame.type === 'done' && assistantMessage ) {
			assistantMessage.pending = false;

			if ( assistantMessage.timing ) {
				assistantMessage.timing.completedAt = getTimestamp();
			}

			if ( typeof frame.payload?.text === 'string' && frame.payload.text !== '' ) {
				assistantMessage.content = frame.payload.text;
			}

			setModelDetails( frame.payload?.model );
			render();
			setNotice( '', '' );
			return;
		}

		if ( frame.type === 'error' && assistantMessage ) {
			assistantMessage.pending = false;
			assistantMessage.error = String( frame.payload?.message || 'Streaming failed.' );
			render();
			setNotice( 'error', assistantMessage.error );
		}
	};

	const streamChat = async () => {
		markRequestStarted();

		const response = await fetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
				'Accept': 'text/event-stream',
			},
			body: new URLSearchParams( {
				action: config.action,
				_ajax_nonce: config.nonce,
				messages: JSON.stringify( state.messages.filter( ( message ) => ! message.pending ).map( ( message ) => ( {
					role: message.role,
					content: message.content,
				} ) ) ),
				streaming_enabled: streamingToggle && ! streamingToggle.checked ? '0' : '1',
				system_prompt: systemPrompt ? systemPrompt.value : '',
				max_tokens: maxTokens ? maxTokens.value : '',
			} ).toString(),
		} );

		if ( ! response.ok ) {
			const rawText = await response.text();
			let message = rawText || 'Streaming request failed.';

			try {
				const parsed = JSON.parse( rawText );
				message = parsed?.data?.message || parsed?.message || message;
			} catch ( error ) {
				// Ignore parse failures and keep the raw text response.
			}

			throw new Error( message );
		}

		if ( ! response.body ) {
			throw new Error( 'The browser did not expose a readable response stream.' );
		}

			const reader = response.body.getReader();
			const decoder = new TextDecoder();
			let buffer = '';

			while ( true ) {
			const { done, value } = await reader.read();

			buffer += decoder.decode( value || new Uint8Array(), { stream: ! done } );

				const blocks = buffer.split( '\n\n' );
				buffer = blocks.pop() || '';

				blocks.forEach( ( block ) => {
					const parsedBlock = parseSseBlock( block );

					if ( ! parsedBlock ) {
						return;
					}

					handleStreamFrame( parsedBlock );
				} );

				if ( done ) {
					if ( buffer.trim() ) {
						const parsedBlock = parseSseBlock( buffer );

						if ( parsedBlock ) {
							handleStreamFrame( parsedBlock );
						}
					}

					break;
				}
			}
		};

	const parseSseBlock = ( block ) => {
		const lines = block.split( '\n' );
		let type = 'message';
		const dataLines = [];

			lines.forEach( ( line ) => {
				if ( ! line || line.startsWith( ':' ) ) {
					return;
				}

				if ( line.startsWith( 'event:' ) ) {
					type = line.slice( 6 ).trim() || type;
					return;
				}

				if ( line.startsWith( 'data:' ) ) {
					dataLines.push( line.slice( 5 ).trimStart() );
				}
			} );

			if ( ! dataLines.length ) {
				return null;
			}

			const data = dataLines.join( '\n' );

			try {
				return JSON.parse( data );
			} catch ( error ) {
				console.error( 'Invalid SSE frame', error, block );
				return null;
			}
		};

	input.addEventListener( 'keydown', ( event ) => {
		if ( event.key !== 'Enter' || event.shiftKey ) {
			return;
		}

		event.preventDefault();

		if ( typeof form.requestSubmit === 'function' ) {
			form.requestSubmit();
			return;
		}

		form.dispatchEvent( new Event( 'submit', { cancelable: true, bubbles: true } ) );
	} );

	form.addEventListener( 'submit', async ( event ) => {
		event.preventDefault();

		if ( state.isStreaming ) {
			return;
		}

		const content = input.value.trim();

		if ( ! content ) {
			setNotice( 'warning', 'Enter a message before sending.' );
			return;
		}

		setNotice( '', '' );

		state.messages.push( {
			role: 'user',
			content,
		} );
		state.messages.push( createAssistantPlaceholder() );
		input.value = '';
		render();
		setBusy( true );

		try {
			await streamChat();
		} catch ( error ) {
			const assistantMessage = getAssistantMessage();
			const message = error instanceof Error ? error.message : 'Streaming request failed.';

			if ( assistantMessage ) {
				assistantMessage.pending = false;
				assistantMessage.error = message;
			}

			setNotice( 'error', message );
			render();
		} finally {
			setBusy( false );
			input.focus();
		}
	} );

	clearButton.addEventListener( 'click', () => {
		if ( state.isStreaming ) {
			return;
		}

		state.messages = [];
		setNotice( '', '' );
		render();
		input.focus();
	} );

	render();
}() );
