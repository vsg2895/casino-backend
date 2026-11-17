{{-- Plain-text part of a scheduled Custom HTML campaign. Unescaped on purpose:
     this is text/plain, where an entity such as &amp; would be printed literally.

     The opt-out line is appended here unconditionally when there is a URL — the
     HTML part only adds one when the typed body does not already carry it, but a
     text part generated from that same body has no reliable way to tell a link
     from the words around it, and a bulk message must offer a way out in both
     parts. --}}
{!! $bodyText !!}
@if ($unsubscribeUrl !== '')

--
You are receiving this because you subscribed.
Unsubscribe: {!! $unsubscribeUrl !!}
@endif
