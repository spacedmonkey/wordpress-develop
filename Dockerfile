FROM wordpressdevelop/php:latest

#Install XHProf:
RUN if [ -z "$(pecl list | grep xhprof)" ] ; then pecl install xhprof ; fi
RUN docker-php-ext-enable xhprof
RUN echo '[xhprof]' >> /usr/local/etc/php/php.ini
RUN echo 'extension=xhprof.so' >> /usr/local/etc/php/php.ini
RUN echo 'xhprof.output_dir="/tmp/xhprof"' >> /usr/local/etc/php/php.ini
