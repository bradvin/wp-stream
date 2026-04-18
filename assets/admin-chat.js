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
	const systemPrompt = document.getElementById( 'wp-stream-system-prompt' );
	const temperature = document.getElementById( 'wp-stream-temperature' );
	const maxTokens = document.getElementById( 'wp-stream-max-tokens' );

	if ( ! form || ! input || ! log || ! clearButton || ! notice || ! spinner ) {
		return;
	}

	const state = {
		messages: [],
		isStreaming: false,
	};

	const setNotice = ( type, message ) => {
		if ( ! message ) {
			notice.className = 'notice inline hidden';
			notice.innerHTML = '';
			return;
		}

		notice.className = 'notice inline notice-' + type;
		notice.innerHTML = '<p>' + escapeHtml( message ) + '</p>';
	};

	const setBusy = ( isBusy ) => {
		state.isStreaming = isBusy;
		form.querySelector( 'button[type="submit"]' ).disabled = isBusy;
		input.disabled = isBusy;
		clearButton.disabled = isBusy;
		spinner.classList.toggle( 'is-active', isBusy );
	};

	const render = () => {
		if ( ! state.messages.length ) {
			log.innerHTML = '<div class="wp-stream-chat__empty">Send a message to watch the assistant response stream into the transcript.</div>';
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
			return '<p class="wp-stream-chat__typing">Waiting for streamed output…</p>';
		}

		return '<p>' + escapeHtml( message.content || '' ).replaceAll( '\n', '<br>' ) + '</p>';
	};

	const escapeHtml = ( value ) => {
		return value
			.replaceAll( '&', '&amp;' )
			.replaceAll( '<', '&lt;' )
			.replaceAll( '>', '&gt;' )
			.replaceAll( '"', '&quot;' )
			.replaceAll( "'", '&#039;' );
	};

	const createAssistantPlaceholder = () => ( {
		role: 'assistant',
		content: '',
		pending: true,
	} );

	const getAssistantMessage = () => {
		for ( let index = state.messages.length - 1; index >= 0; index -= 1 ) {
			if ( state.messages[ index ].role === 'assistant' ) {
				return state.messages[ index ];
			}
		}

		return null;
	};

	const handleStreamFrame = ( frame ) => {
		if ( ! frame || typeof frame !== 'object' ) {
			return;
		}

		const assistantMessage = getAssistantMessage();

		if ( frame.type === 'delta' && assistantMessage ) {
			assistantMessage.content += String( frame.payload?.text || '' );
			render();
			return;
		}

		if ( frame.type === 'done' && assistantMessage ) {
			assistantMessage.pending = false;

			if ( typeof frame.payload?.text === 'string' && frame.payload.text !== '' ) {
				assistantMessage.content = frame.payload.text;
			}

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
		const response = await fetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
				'Accept': 'application/x-ndjson',
			},
			body: new URLSearchParams( {
				action: config.action,
				_ajax_nonce: config.nonce,
				messages: JSON.stringify( state.messages.filter( ( message ) => ! message.pending ).map( ( message ) => ( {
					role: message.role,
					content: message.content,
				} ) ) ),
				system_prompt: systemPrompt ? systemPrompt.value : '',
				temperature: temperature ? temperature.value : '',
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

			const lines = buffer.split( '\n' );
			buffer = lines.pop() || '';

			lines.forEach( ( line ) => {
				const trimmed = line.trim();

				if ( ! trimmed ) {
					return;
				}

				try {
					handleStreamFrame( JSON.parse( trimmed ) );
				} catch ( error ) {
					console.error( 'Invalid stream frame', error, trimmed );
				}
			} );

			if ( done ) {
				if ( buffer.trim() ) {
					try {
						handleStreamFrame( JSON.parse( buffer.trim() ) );
					} catch ( error ) {
						console.error( 'Invalid final stream frame', error, buffer.trim() );
					}
				}

				break;
			}
		}
	};

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
